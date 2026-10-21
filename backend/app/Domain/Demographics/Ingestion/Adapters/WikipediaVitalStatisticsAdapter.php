<?php

namespace App\Domain\Demographics\Ingestion\Adapters;

use App\Domain\Demographics\Import\ValueParser;
use App\Domain\Demographics\Ingestion\Contracts\SourceAdapter;
use App\Domain\Demographics\Ingestion\Dto\ObservationDraft;
use App\Domain\Demographics\Ingestion\Dto\RawDataset;
use App\Domain\Demographics\Ingestion\SnapshotStore;
use App\Domain\Demographics\Ingestion\Validation\IndicatorSanity;
use App\Models\Entity;
use App\Models\Indicator;
use App\Models\SourceDataset;
use App\Support\HttpClient;

/**
 * Scrapes the vital-statistics tables from each country's "Demographics of ..."
 * article on Wikipedia.
 *
 * These tables are what the module wants for per-country history: mid-year
 * population, live births, deaths, natural change, crude birth and death rates,
 * migration rate, total fertility, infant mortality and life expectancy, usually by
 * year from the 1950s onward.
 *
 * Wikipedia is a secondary source (rank 4). The tables are themselves compiled from
 * national statistics offices or UN WPP, so the module records them as such and never
 * lets them displace a higher-ranked source — but for series no official feed
 * publishes (annual births and deaths, natural change) they are the difference
 * between an empty column and a usable one.
 *
 * Columns are matched by header text, because the tables differ between articles.
 */
class WikipediaVitalStatisticsAdapter implements SourceAdapter
{
    /**
     * Article titles that differ from the entity name.
     */
    private const TITLE_OVERRIDES = [
        'CIV' => 'Ivory Coast',
        'CPV' => 'Cape Verde',
        'CZE' => 'Czech Republic',
        'FSM' => 'Federated States of Micronesia',
        'GMB' => 'Gambia',
        'BHS' => 'Bahamas',
        'TLS' => 'East Timor',
        'VAT' => 'Vatican City',
        'PSE' => 'State of Palestine',
        'ALA' => 'Åland',
        'SJM' => 'Svalbard',
        'COD' => 'Democratic Republic of the Congo',
        'COG' => 'Republic of the Congo',
    ];

    /**
     * Header text => indicator code. Keys are ordered: the first match wins, so the
     * more specific phrases must come before the shorter ones they contain.
     */
    private const HEADER_MAP = [
        'crude birth rate' => 'birth.rate.crude',
        'crude death rate' => 'death.rate.crude',
        'rate of natural change' => 'pop.natural_change.rate',
        'natural change (per 1000)' => 'pop.natural_change.rate',
        'natural increase (per 1000)' => 'pop.natural_change.rate',
        'annual change in population' => 'pop.change.annual',
        'natural change' => 'pop.natural_change.absolute',
        'natural increase' => 'pop.natural_change.absolute',
        'crude migration rate' => 'mig.rate.crude',
        'net migration rate' => 'mig.rate.crude',
        'migration change (per 1000)' => 'mig.rate.crude',
        'migration change' => 'mig.rate.crude',
        'births per year' => 'birth.count.annual',
        'live births' => 'birth.count.annual',
        'deaths per year' => 'death.count.annual',
        // Not read from these tables. On the Japan and United States articles the mapped
        // column lands on the crude death rate, so "total fertility rate" arrived as 9.8
        // to 12.9 — plausible-looking, and wrong. The World Bank publishes TFR at a better
        // rank anyway, so the honest thing is to take it from there and leave this empty
        // rather than fill it with a mis-aligned column.
        // 'total fertility rate' => 'fert.tfr',
        'infant mortality' => 'death.infant_rate',
        'life expectancy' => 'death.life_expectancy',
        'mid-year population' => 'pop.total',
        'midyear population' => 'pop.total',
    ];

    public function __construct(
        private readonly SnapshotStore $snapshots,
        private readonly WikitableParser $tables,
        private readonly ValueParser $parser,
    ) {}

    public function key(): string
    {
        return 'wikipedia_vital_statistics';
    }

    public function fetch(SourceDataset $dataset, array $options = []): RawDataset
    {
        $config = config('demographics.adapters.wikipedia');
        $limit = $options['limit'] ?? $dataset->config['max_articles'] ?? null;
        $entityIds = $options['entity_ids'] ?? null;

        $entities = Entity::query()
            ->whereNotNull('iso_alpha2')
            ->whereIn('entity_type', ['country', 'dependency', 'territory'])
            ->when($entityIds !== null, fn ($query) => $query->whereIn('id', $entityIds))
            ->when($entityIds === null && $limit !== null, fn ($query) => $query->limit((int) $limit))
            ->orderBy('name')
            ->get(['id', 'name', 'iso_alpha3']);

        $articles = [];
        $withoutData = [];
        $lastStatus = null;

        foreach ($entities as $entity) {
            $article = $this->fetchArticle($entity, $config, $lastStatus);

            if ($article === null) {
                $withoutData[] = ['entity_id' => $entity->id, 'name' => $entity->name, 'reason' => 'article_not_found'];

                continue;
            }

            $tables = $this->tables->parse($article['wikitext']);
            $usable = array_values(array_filter(
                $tables,
                fn (array $table): bool => $this->isUsable($table)
            ));

            if ($usable === []) {
                $withoutData[] = ['entity_id' => $entity->id, 'name' => $entity->name, 'reason' => 'no_usable_table'];

                continue;
            }

            // Only the tables we can actually read are kept in the snapshot: the rest
            // of the article is not evidence for anything.
            $articles[(string) $entity->id] = [
                'entity_id' => $entity->id,
                'name' => $entity->name,
                'title' => $article['title'],
                'pageid' => $article['pageid'],
                'url' => $article['url'],
                // Articles often carry two vital-statistics tables (UN estimates and a
                // national series). Only the most complete one is kept: writing both
                // would have each overwrite the other for the same year.
                'tables' => [$this->selectBestTable($usable)],
                'tables_considered' => count($usable),
            ];

            if (isset($config['min_interval_ms'])) {
                usleep((int) $config['min_interval_ms'] * 1000);
            }
        }

        $snapshot = $this->snapshots->store(
            $dataset,
            (string) json_encode($articles),
            (string) $config['api_url'],
            $lastStatus,
            null,
            'application/json',
        );

        return new RawDataset(
            dataset: $dataset,
            snapshot: $snapshot,
            payload: $articles,
            meta: [
                'http_status' => $lastStatus,
                'articles_with_tables' => count($articles),
                'entities_checked' => $entities->count(),
                'entities_without_data' => $withoutData,
            ],
        );
    }

    /**
     * The table that yields the most indicators, then the most rows. Deterministic, so
     * re-scraping an unchanged article produces the same series.
     *
     * @param  array<int, array{headers: array<int, string>, rows: array<int, array<int, string>>}>  $usable
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
     */
    private function selectBestTable(array $usable): array
    {
        $best = $usable[0];
        $bestScore = -1;

        foreach ($usable as $table) {
            // Score by how many *distinct* indicators the table yields, not by column
            // count. Some articles carry a wide historical table with the same metric
            // repeated once per scholarly estimate; counting columns would prefer it
            // over the single comprehensive vital-statistics table.
            $distinct = count(array_unique($this->mapHeaders($table['headers'])['indicators']));

            $score = $distinct * 10000 + count($table['rows']);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $table;
            }
        }

        return $best;
    }

    /**
     * @return array{title: string, pageid: int|null, url: string, wikitext: string}|null
     */
    private function fetchArticle(Entity $entity, array $config, ?int &$lastStatus): ?array
    {
        foreach ($this->candidateTitles($entity) as $title) {
            $response = HttpClient::make(['User-Agent' => $config['user_agent']])
                ->get($config['api_url'], [
                    'action' => 'parse',
                    'page' => $title,
                    'prop' => 'wikitext',
                    'format' => 'json',
                    'formatversion' => 2,
                    'redirects' => 1,
                ]);

            $lastStatus = $response->status();
            $wikitext = $response->json('parse.wikitext');

            if ($response->failed() || ! is_string($wikitext) || $wikitext === '') {
                continue;
            }

            $resolved = (string) $response->json('parse.title', $title);

            return [
                'title' => $resolved,
                'pageid' => $response->json('parse.pageid'),
                'url' => 'https://en.wikipedia.org/wiki/'.rawurlencode(str_replace(' ', '_', $resolved)),
                'wikitext' => $wikitext,
            ];
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function candidateTitles(Entity $entity): array
    {
        $base = self::TITLE_OVERRIDES[$entity->iso_alpha3] ?? $entity->name;

        return array_values(array_unique([
            'Demographics of '.$base,
            'Demographics of the '.$base,
        ]));
    }

    /**
     * Whole-header matches, checked before the substring rules below.
     */
    private const EXACT_MAP = [
        'births' => 'birth.count.annual',
        'deaths' => 'death.count.annual',
        'population' => 'pop.total',
        'total population' => 'pop.total',
        'natural change' => 'pop.natural_change.absolute',
        'natural increase' => 'pop.natural_change.absolute',
    ];

    /**
     * @param  array<int, string>  $headers
     * @return array{indicators: array<int, string>, year: int|null}
     */
    private function mapHeaders(array $headers): array
    {
        $indicators = [];
        $year = null;

        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader($header);

            if ($normalized === '') {
                continue;
            }

            if ($normalized === 'year' || str_starts_with($normalized, 'year ') || str_starts_with($normalized, 'year(')) {
                $year ??= $index;

                continue;
            }

            $code = $this->matchIndicator($normalized);

            if ($code !== null) {
                $indicators[$index] = $code;
            }
        }

        return ['indicators' => $indicators, 'year' => $year];
    }

    /**
     * The column holding the year: the labelled one, or an unlabelled leading column
     * whose first value parses as a year. Several European articles leave the year
     * header blank, so requiring a "Year" label loses whole countries.
     */
    private function yearColumn(array $table, array $mapping): ?int
    {
        if ($mapping['year'] !== null) {
            return $mapping['year'];
        }

        $first = $table['rows'][0][0] ?? null;

        if ($first === null) {
            return null;
        }

        return $this->parser->year($first)->isValue() ? 0 : null;
    }

    private function isUsable(array $table): bool
    {
        $mapping = $this->mapHeaders($table['headers']);

        return $mapping['indicators'] !== [] && $this->yearColumn($table, $mapping) !== null;
    }

    private function normalizeHeader(string $header): string
    {
        $text = str_replace(['<br />', '<br/>', '<br>'], ' ', $header);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = mb_strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
        $text = str_replace("\u{2212}", '-', $text);

        return $text;
    }

    /**
     * Sex-specific columns are skipped: several articles split life expectancy into
     * male and female columns, which would otherwise collapse onto one indicator and
     * overwrite each other for every year.
     */
    private const SKIP_MARKERS = ['male', 'female', 'men', 'women'];

    private function matchIndicator(string $normalized): ?string
    {
        foreach (self::SKIP_MARKERS as $marker) {
            if (preg_match('/\b'.$marker.'\b/', $normalized)) {
                return null;
            }
        }

        if (isset(self::EXACT_MAP[$normalized])) {
            return self::EXACT_MAP[$normalized];
        }

        foreach (self::HEADER_MAP as $needle => $code) {
            if (str_contains($normalized, $needle)) {
                return $code;
            }
        }

        // "Population (1 January)", "Population at 1 July" and similar dated columns.
        if (str_starts_with($normalized, 'population')) {
            return 'pop.total';
        }

        return null;
    }

    public function normalize(RawDataset $raw): iterable
    {
        $indicatorIds = Indicator::query()->pluck('id', 'code');
        $currentYear = (int) now()->year;
        $implausible = 0;
        $implausibleExamples = [];
        $misalignedRows = 0;
        $misalignedArticles = [];

        foreach ($raw->payload as $article) {
            foreach ($article['tables'] as $table) {
                $mapping = $this->mapHeaders($table['headers']);
                $yearColumn = $this->yearColumn($table, $mapping);

                if ($mapping['indicators'] === [] || $yearColumn === null) {
                    continue;
                }

                $width = count($table['headers']);
                $misaligned = 0;

                foreach ($table['rows'] as $row) {
                    /*
                     * A row whose cell count differs from the header row cannot be read by
                     * header index: every column after the gap shifts by one. This is not a
                     * hypothetical — it is how "total fertility rate" arrived as the crude
                     * death rate on the Japan and United States articles (9.8 to 12.9, which
                     * looks like a number and is not one). A shifted row is refused whole,
                     * and the count is reported, because dropping it silently would hide the
                     * fact that the article's table does not line up.
                     */
                    if (count($row) !== $width) {
                        $misaligned++;

                        continue;
                    }

                    $year = $this->parser->year($row[$yearColumn] ?? null);

                    if (! $year->isValue() || $year->year === null) {
                        continue;
                    }

                    foreach ($mapping['indicators'] as $column => $code) {
                        $indicatorId = $indicatorIds->get($code);

                        if ($indicatorId === null) {
                            continue;
                        }

                        $cell = $row[$column] ?? null;
                        $parsed = $this->parser->number($cell);

                        // Absent cells are not written: a Wikipedia table stopping at
                        // 2020 does not assert anything about 2021.
                        if (! $parsed->isValue() || $parsed->value === null) {
                            continue;
                        }

                        if (! IndicatorSanity::isPlausible($code, (float) $parsed->value)) {
                            // A column has drifted out of alignment. Store nothing and
                            // report it rather than let an impossible figure through.
                            $implausible++;

                            if (count($implausibleExamples) < 10) {
                                $implausibleExamples[] = [
                                    'indicator' => $code,
                                    'year' => $year->year,
                                    'value' => $parsed->value,
                                ];
                            }

                            continue;
                        }

                        // The tables run into the future. A figure for a year that has
                        // not happened yet is a projection and must be labelled as one.
                        $isProjection = $year->year > $currentYear;

                        yield new ObservationDraft(
                            entityId: (int) $article['entity_id'],
                            indicatorId: (int) $indicatorId,
                            periodType: 'annual',
                            periodYear: $year->year,
                            value: $parsed->value,
                            rawValue: $parsed->rawValue,
                            status: $isProjection ? 'projected' : $parsed->status,
                            isEstimate: $parsed->isEstimate,
                            isProjection: $isProjection,
                            sourceId: $raw->dataset->source_id,
                            sourceDatasetId: $raw->dataset->id,
                            sourceSnapshotId: $raw->snapshot->id,
                            sourceRecordRef: 'wikipedia:'.$article['title'].':'.$code.':'.$year->year,
                            sourceUrl: $article['url'],
                            referenceNote: sprintf(
                                'Wikipedia "%s", vital statistics table (revision %s). The table is itself compiled from national statistics or UN data; follow its own citations for the authoritative figure.',
                                $article['title'],
                                $article['pageid'] ?? 'unknown',
                            ),
                        );
                    }
                }

                if ($misaligned > 0) {
                    $misalignedRows += $misaligned;
                    $misalignedArticles[] = $article['title'].': '.$misaligned.'/'.count($table['rows']);
                }
            }
        }

        // Runs once the generator is exhausted, before the pipeline writes its report.
        if ($misalignedRows > 0) {
            $raw->meta['misaligned_rows_refused'] = $misalignedRows;
            $raw->meta['misaligned_examples'] = array_slice($misalignedArticles, 0, 20);
        }

        if ($implausible > 0) {
            $raw->meta['implausible_values'] = $implausible;
            $raw->meta['implausible_examples'] = $implausibleExamples;
        }
    }
}
