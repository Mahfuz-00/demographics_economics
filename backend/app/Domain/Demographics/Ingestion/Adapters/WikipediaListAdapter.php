<?php

namespace App\Domain\Demographics\Ingestion\Adapters;

use App\Domain\Demographics\Import\EntityResolver;
use App\Domain\Demographics\Import\ValueParser;
use App\Domain\Demographics\Ingestion\Contracts\SourceAdapter;
use App\Domain\Demographics\Ingestion\Dto\ObservationDraft;
use App\Domain\Demographics\Ingestion\Dto\RawDataset;
use App\Domain\Demographics\Ingestion\SnapshotStore;
use App\Domain\Demographics\Ingestion\Validation\IndicatorSanity;
use App\Models\Indicator;
use App\Models\SourceDataset;
use App\Support\HttpClient;
use RuntimeException;

/**
 * Scrapes Wikipedia's **collective list pages** — one page ranking every country —
 * rather than an article per country.
 *
 * That is how Economy, Debt and Wealth are published: there is no "Economy of France"
 * table to match "Demographics of France", only "List of countries by GDP (nominal)",
 * "List of countries by external debt" and so on.
 *
 * These pages have nothing in common with each other, so nothing about reading one is
 * inferred from another:
 *
 *  - **GDP (nominal)** has a *publisher* per column — `IMF (2026)`, `World Bank (2025)` —
 *    so the period comes from the header and the values are in millions, not billions.
 *  - **Corporate debt** is transposed: one column per year, not one row per year.
 *  - **Household debt** reports quarters.
 *
 * A dictionary that matched header *wording* would read the GDP table's `30,337,162`
 * (millions) as billions, and a quarter as a year. Both are worse than no data, because
 * they look plausible. So every column a page contributes is named explicitly in that
 * page's configuration, and a page without configuration contributes nothing. Anything
 * a page offers that is not configured is reported as unmatched rather than guessed at.
 */
class WikipediaListAdapter implements SourceAdapter
{
    public function __construct(
        private readonly SnapshotStore $snapshots,
        private readonly WikitableParser $tables,
        private readonly ValueParser $parser,
        private readonly EntityResolver $resolver,
    ) {}

    public function key(): string
    {
        return 'wikipedia_list';
    }

    public function fetch(SourceDataset $dataset, array $options = []): RawDataset
    {
        $config = config('demographics.adapters.wikipedia');
        $pages = $dataset->config['pages'] ?? [];
        $wanted = $options['page'] ?? null;

        if ($pages === []) {
            throw new RuntimeException(
                "Dataset [{$dataset->code}] registers the wikipedia_list adapter but lists no pages."
            );
        }

        $fetched = [];
        $skipped = [];
        $unreachable = [];
        $lastStatus = null;

        foreach ($pages as $page) {
            $key = $page['key'] ?? '?';

            if ($wanted !== null && $key !== $wanted) {
                continue;
            }

            // A page is only read once its columns have been pinned to this source's own
            // headers. Until then it is listed as skipped, with the reason, rather than
            // read approximately.
            if (($page['readable'] ?? false) !== true) {
                $skipped[] = ['page' => $key, 'title' => $page['titles'][0] ?? '?', 'reason' => $page['reason'] ?? 'not_configured'];

                continue;
            }

            $article = $this->fetchPage($page['titles'] ?? [], $config, $lastStatus);

            if ($article === null) {
                $unreachable[] = ['page' => $key, 'tried' => $page['titles'] ?? [], 'reason' => 'page_not_found'];

                continue;
            }

            $fetched[$key] = [
                'key' => $key,
                'title' => $article['title'],
                'pageid' => $article['pageid'],
                'revid' => $article['revid'],
                'revised_at' => $article['revised_at'],
                'url' => $article['url'],
                'tables' => $this->tables->parse($article['wikitext']),
            ];

            if (isset($config['min_interval_ms'])) {
                usleep((int) $config['min_interval_ms'] * 1000);
            }
        }

        $snapshot = $this->snapshots->store(
            $dataset,
            (string) json_encode($fetched),
            (string) $config['api_url'],
            $lastStatus,
            null,
            'application/json',
        );

        return new RawDataset(
            dataset: $dataset,
            snapshot: $snapshot,
            payload: $fetched,
            meta: [
                'http_status' => $lastStatus,
                'pages_fetched' => count($fetched),
                'pages_skipped' => $skipped,
                'pages_unreachable' => $unreachable,
            ],
        );
    }

    /**
     * @return array{title: string, pageid: int|null, revid: int|null, revised_at: string|null, url: string, wikitext: string}|null
     */
    private function fetchPage(array $titles, array $config, ?int &$lastStatus): ?array
    {
        foreach ($titles as $title) {
            $response = HttpClient::make(['User-Agent' => $config['user_agent']])
                ->get($config['api_url'], [
                    'action' => 'query',
                    'prop' => 'revisions',
                    'rvslots' => 'main',
                    'rvprop' => 'ids|timestamp|content',
                    'titles' => $title,
                    'format' => 'json',
                    'formatversion' => 2,
                    'redirects' => 1,
                ]);

            $lastStatus = $response->status();

            if ($response->failed()) {
                continue;
            }

            $page = $response->json('query.pages.0');

            // A missing page is a normal response with `missing` set, not an error.
            if (! is_array($page) || isset($page['missing'])) {
                continue;
            }

            $wikitext = $page['revisions'][0]['slots']['main']['content'] ?? null;

            if (! is_string($wikitext) || $wikitext === '') {
                continue;
            }

            $resolved = (string) ($page['title'] ?? $title);

            return [
                'title' => $resolved,
                'pageid' => $page['pageid'] ?? null,
                'revid' => $page['revisions'][0]['revid'] ?? null,
                'revised_at' => $page['revisions'][0]['timestamp'] ?? null,
                'url' => 'https://en.wikipedia.org/wiki/'.rawurlencode(str_replace(' ', '_', $resolved)),
                'wikitext' => $wikitext,
            ];
        }

        return null;
    }

    public function normalize(RawDataset $raw): iterable
    {
        $indicators = Indicator::query()->pluck('id', 'code');
        $currentYear = (int) now()->year;

        $pageConfig = collect($raw->dataset->config['pages'] ?? [])->keyBy('key');

        $report = [];
        $implausible = 0;
        $implausibleExamples = [];
        $unresolved = [];
        $assumed = 0;

        foreach ($raw->payload as $key => $page) {
            $config = $pageConfig->get($key) ?? [];
            $table = $this->selectTable($page['tables'], $config);

            if ($table === null) {
                $report[$key] = [
                    'title' => $page['title'],
                    'tables_considered' => count($page['tables']),
                    'reason' => 'no_table_with_the_configured_country_column_and_at_least_one_value_column',
                    'headers_seen' => array_map(fn (array $t): array => $t['headers'], $page['tables']),
                ];

                continue;
            }

            $mapping = $this->mapHeaders($table['headers'], $config);
            $revisionYear = $this->revisionYear($page['revised_at']);

            $report[$key] = [
                'title' => $page['title'],
                'url' => $page['url'],
                'tables_considered' => count($page['tables']),
                'headers' => $table['headers'],
                'entity_column' => $mapping['entity'],
                'mapped' => array_map(
                    fn (array $entry): string => $entry['indicator'],
                    $mapping['columns']
                ),
                'unmatched' => $mapping['unmatched'],
                'revision_year' => $revisionYear,
            ];

            foreach ($table['rows'] as $row) {
                $name = $this->cleanEntityCell($row[$mapping['entity']] ?? null);

                if ($name === null) {
                    continue;
                }

                $entity = $this->resolver->resolve($name);

                if ($entity === null) {
                    if (count($unresolved) < 40) {
                        $unresolved[] = ['page' => $key, 'name' => $name];
                    }

                    continue;
                }

                foreach ($mapping['columns'] as $column => $entry) {
                    $indicatorId = $indicators->get($entry['indicator']);

                    if ($indicatorId === null) {
                        continue;
                    }

                    $cellText = $row[$column] ?? null;

                    // These pages write positive figures with an explicit sign — `+50,000`
                    // on the NIIP table. Without stripping it the positive rows fail to
                    // parse and only the negatives survive, which is exactly what happened:
                    // every country came back a net debtor.
                    if (is_string($cellText)) {
                        $cellText = preg_replace('/^\s*\+/', '', $cellText) ?? $cellText;
                    }

                    $parsed = $this->parser->number($cellText);

                    if (! $parsed->isValue() || $parsed->value === null) {
                        continue;
                    }

                    // The period is either stated per row, or carried by this column's
                    // own header ("IMF (2026)"), or absent — in which case the value is
                    // as-of the revision it was read from.
                    $columnYear = $entry['year'];
                    $isAssumed = false;

                    if ($columnYear !== null) {
                        $year = $columnYear;
                    } elseif ($mapping['year'] !== null) {
                        $stated = $this->parser->year($row[$mapping['year']] ?? null);

                        if ($stated === null || ! $stated->isValue() || $stated->year === null) {
                            continue;
                        }

                        $year = $stated->year;
                    } else {
                        $year = $revisionYear;
                        $isAssumed = true;
                    }

                    if ($year === null) {
                        continue;
                    }

                    if ($isAssumed) {
                        $assumed++;
                    }

                    $value = (float) $parsed->value * (float) $entry['scale'];

                    if (! IndicatorSanity::isPlausible($entry['indicator'], $value)) {
                        $implausible++;

                        if (count($implausibleExamples) < 10) {
                            $implausibleExamples[] = [
                                'page' => $key,
                                'indicator' => $entry['indicator'],
                                'raw' => $parsed->rawValue,
                            ];
                        }

                        continue;
                    }

                    yield new ObservationDraft(
                        entityId: (int) $entity->id,
                        indicatorId: (int) $indicatorId,
                        periodType: 'annual',
                        periodYear: $year,
                        value: round($value, 4),
                        rawValue: $parsed->rawValue,
                        status: 'reported',
                        periodIsAssumed: $isAssumed,
                        isProjection: $year > $currentYear,
                        sourceId: $raw->dataset->source_id,
                        sourceDatasetId: $raw->dataset->id,
                        sourceSnapshotId: $raw->snapshot->id,
                        sourceRecordRef: 'wikipedia-list:'.$key.':'.$entry['indicator'].':'.$entry['header'].':'.$name.':'.$year,
                        sourceUrl: $page['url'],
                        referenceNote: sprintf(
                            'Wikipedia "%s"%s, column "%s" of its "list of countries" table.%s',
                            $page['title'],
                            $page['revid'] !== null ? ' (revision '.$page['revid'].')' : '',
                            $entry['header'],
                            $isAssumed
                                ? ' The page states no period for this column, so it is dated to the revision year and flagged as assumed.'
                                : ''
                        ),
                    );
                }
            }
        }

        $raw->meta['pages'] = $report;
        $raw->meta['assumed_period_values'] = $assumed;

        if ($unresolved !== []) {
            $raw->meta['unresolved_countries'] = $unresolved;
        }

        if ($implausible > 0) {
            $raw->meta['implausible_values'] = $implausible;
            $raw->meta['implausible_examples'] = $implausibleExamples;
        }
    }

    /**
     * The table to read. Several of these pages stack a table per publishing body, and
     * reading all of them would file two figures for one country and indicator from one
     * source. The table mapping the most configured indicators wins; ties go to the
     * first, so a re-read picks the same table.
     *
     * @param  array<int, array{headers: array<int, string>, rows: array<int, array<int, string>>}>  $tables
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}|null
     */
    private function selectTable(array $tables, array $config): ?array
    {
        $best = null;
        $bestScore = -1;

        foreach ($tables as $table) {
            $mapping = $this->mapHeaders($table['headers'], $config);

            if ($mapping['entity'] === null || $mapping['columns'] === []) {
                continue;
            }

            $score = count($mapping['columns']) * 10000 + count($table['rows']);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $table;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, string>  $headers
     * @return array{entity: int|null, year: int|null, columns: array<int, array{indicator: string, scale: float, year: int|null}>, unmatched: array<int, string>}
     */
    private function mapHeaders(array $headers, array $config): array
    {
        $entity = null;
        $year = null;
        $columns = [];
        $unmatched = [];

        $entityNeedles = $config['entity_headers'] ?? ['country'];
        $valueNeedles = $config['columns'] ?? [];
        $skipNeedles = $config['skip'] ?? [];
        $periodFromHeader = ($config['period'] ?? 'revision') === 'header_year';

        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader($header);

            if ($normalized === '') {
                continue;
            }

            if ($entity === null && $this->containsAny($normalized, $entityNeedles)) {
                $entity = $index;

                continue;
            }

            // Configured columns are checked before any year detection. Several of these
            // pages are one column per year — `Country | 2023 | 2022 | 2021` — and those
            // headers are years; treating them as a period column would discard exactly
            // the column the page exists to publish.
            $entry = null;
            $code = null;

            foreach ($valueNeedles as $needle => $definition) {
                $needle = (string) $needle;

                // A bare year must match the whole header. `2020-2025` contains `2025`,
                // so a substring test would read a five-year cumulative as the latest
                // year's figure — which is exactly what the FDI page offers.
                $matched = preg_match('/^\d{4}$/', $needle) === 1
                    ? $normalized === $needle
                    : str_contains($normalized, $needle);

                if ($matched) {
                    $code = $definition['indicator'];
                    $entry = $definition;

                    break;
                }
            }

            if ($code !== null) {
                // A heading's index is not always its cell's index: a cell holding a
                // `{{n+p|disp=table|value|share}}` renders two columns, so everything
                // after it sits one place left. A column that knows its own cell says so.
                $target = isset($entry['cell']) ? (int) $entry['cell'] : $index;

                $columns[$target] = [
                    'indicator' => $code,
                    'scale' => (float) ($entry['scale'] ?? 1.0),
                    // A column states its own vintage when the header carries one.
                    'year' => $periodFromHeader ? $this->yearFromHeader($normalized) : null,
                    // The header is kept verbatim: these pages put several competing
                    // columns against one indicator (IMF, World Bank, UN, CIA), so a
                    // value is only traceable if the column it came from is named.
                    'header' => $this->columnLabel($header),
                ];

                continue;
            }

            if ($this->isYearHeader($normalized)) {
                $year ??= $index;

                continue;
            }

            if (! $this->containsAny($normalized, $skipNeedles) && $index > 0) {
                $unmatched[] = $header;
            }
        }

        return ['entity' => $entity, 'year' => $year, 'columns' => $columns, 'unmatched' => $unmatched];
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isYearHeader(string $normalized): bool
    {
        return $normalized === 'year'
            || str_starts_with($normalized, 'year ')
            || str_starts_with($normalized, 'year(')
            || $normalized === 'as of';
    }

    /**
     * The vintage a column publishes. "IMF (2026)" is 2026; "United Nations (2023-24)"
     * covers a period, and is read as its first year — the revision URL is stored with
     * every observation so the exact wording can always be checked.
     */
    private function yearFromHeader(string $normalized): ?int
    {
        if (preg_match('/\b(19|20)\d{2}\b/', $normalized, $matches) !== 1) {
            return null;
        }

        $year = (int) $matches[0];

        return $year > 1900 && $year < 2200 ? $year : null;
    }

    private function revisionYear(?string $timestamp): int
    {
        $year = $timestamp !== null ? (int) substr($timestamp, 0, 4) : 0;

        return $year > 1900 ? $year : (int) now()->year;
    }

    /**
     * The wikitable parser has already reduced the cell to readable text: `[[China]]`,
     * `[[South Korea|Korea]]` and `{{flagcountry|India}}` all arrive as names. All that
     * is left is to drop footnote markers and tighten the spacing.
     */
    private function cleanEntityCell(?string $cell): ?string
    {
        if ($cell === null || trim($cell) === '') {
            return null;
        }

        $text = preg_replace('/\[\d+\]/', '', $cell) ?? $cell;
        $text = preg_replace('/\s*[†*]\s*/u', ' ', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return $text === '' ? null : $text;
    }

    /**
     * The column name, as a person would read it off the page.
     *
     * Header cells carry wikitext that is not part of the label: italic marks, invisible
     * section anchors (which leave `IMF ( 2026 )` behind), and sometimes a prose note
     * after the column name ("... The following countries are from 2020-24 data").
     */
    private function columnLabel(string $header): string
    {
        $text = str_replace(["'''", "''"], '', $header);
        $text = preg_replace('/<[^>]+>/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = preg_replace('/\(\s+/', '(', $text) ?? $text;
        $text = preg_replace('/\s+\)/', ')', $text) ?? $text;
        $text = trim($text);

        // A note after the column name is page commentary, not the name.
        $parts = preg_split('/\s*\bThe following countries\b/i', $text);

        return trim($parts[0] ?? $text);
    }

    private function normalizeHeader(string $header): string
    {
        $text = preg_replace('/<ref[^>]*\/>/i', '', $header) ?? $header;
        $text = preg_replace('/<ref[^>]*>.*?<\/ref>/is', '', $text) ?? $text;
        $text = preg_replace('/\{\{[^{}]*\}\}/', ' ', $text) ?? $text;
        $text = str_replace(['<br />', '<br/>', '<br>'], ' ', $text);
        $text = preg_replace('/<[^>]+>/', ' ', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\[\d+\]/', '', $text) ?? $text;
        $text = mb_strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? $text));

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
