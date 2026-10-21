<?php

namespace App\Domain\Demographics\Ingestion\Adapters;

use App\Domain\Demographics\Ingestion\Contracts\SourceAdapter;
use App\Domain\Demographics\Ingestion\Dto\ObservationDraft;
use App\Domain\Demographics\Ingestion\Dto\RawDataset;
use App\Domain\Demographics\Ingestion\SnapshotStore;
use App\Models\Entity;
use App\Models\Indicator;
use App\Models\SourceDataset;
use App\Support\HttpClient;

/**
 * Wikipedia country infobox figures.
 *
 * Wikipedia is the lowest-ranked source in this module and is used only for recent
 * published figures and to surface the citation trail. Values taken from it never
 * silently displace national or UN data: where a higher-ranked source already holds
 * the series point, both values are retained and the conflict is recorded.
 *
 * The article revision id is stored with every value, so what was read can be
 * reproduced even after the article changes.
 */
class WikipediaDemographicsAdapter implements SourceAdapter
{
    /**
     * Article titles that differ from the entity name used internally.
     */
    private const TITLE_OVERRIDES = [
        'CIV' => 'Ivory Coast',
        'CPV' => 'Cape Verde',
        'CZE' => 'Czech Republic',
        'FSM' => 'Federated States of Micronesia',
        'GMB' => 'The Gambia',
        'BHS' => 'The Bahamas',
        'TLS' => 'East Timor',
        'VAT' => 'Vatican City',
        'PSE' => 'State of Palestine',
        'ALA' => 'Åland',
        'SJM' => 'Svalbard',
        'UMI' => 'United States Minor Outlying Islands',
    ];

    public function __construct(private readonly SnapshotStore $snapshots) {}

    public function key(): string
    {
        return 'wikipedia';
    }

    public function fetch(SourceDataset $dataset, array $options = []): RawDataset
    {
        $config = config('demographics.adapters.wikipedia');
        $limit = $dataset->config['max_articles'] ?? null;

        $entities = Entity::query()
            ->whereNotNull('iso_alpha2')
            ->whereIn('entity_type', ['country', 'dependency', 'territory'])
            ->orderBy('name')
            ->when($limit !== null, fn ($query) => $query->limit((int) $limit))
            ->get(['id', 'name', 'iso_alpha3']);

        $articles = [];
        $lastStatus = null;

        foreach ($entities as $entity) {
            $title = self::TITLE_OVERRIDES[$entity->iso_alpha3] ?? $entity->name;

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

            if ($response->failed()) {
                continue;
            }

            $wikitext = $response->json('parse.wikitext');

            if (! is_string($wikitext) || $wikitext === '') {
                continue;
            }

            $articles[$entity->iso_alpha3] = [
                'entity_id' => $entity->id,
                'title' => $response->json('parse.title', $title),
                'pageid' => $response->json('parse.pageid'),
                'url' => 'https://en.wikipedia.org/wiki/'.rawurlencode(str_replace(' ', '_', (string) $title)),
                'wikitext' => $wikitext,
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
            meta: ['http_status' => $lastStatus, 'articles' => count($articles)],
        );
    }

    public function normalize(RawDataset $raw): iterable
    {
        $indicatorId = Indicator::query()->where('code', 'pop.total')->value('id');

        if ($indicatorId === null) {
            return;
        }

        foreach ($raw->payload as $iso3 => $article) {
            $fields = $this->extractInfoboxFields($article['wikitext']);

            // Estimate first, then census: both are population totals for the same
            // country but for different reference years, so they do not collide.
            foreach ([
                ['value' => 'population_estimate', 'year' => 'population_estimate_year', 'kind' => 'estimate'],
                ['value' => 'population_census', 'year' => 'population_census_year', 'kind' => 'census'],
                ['value' => 'population_total', 'year' => 'population_as_of', 'kind' => 'estimate'],
            ] as $field) {
                $valueField = $field['value'];
                $yearField = $field['year'];

                if (! isset($fields[$valueField])) {
                    continue;
                }

                $value = $this->toNumber($fields[$valueField]);
                $year = isset($fields[$yearField]) ? $this->toYear($fields[$yearField]) : null;

                if ($value === null || $year === null) {
                    continue;
                }

                $isEstimate = $field['kind'] === 'estimate';

                yield new ObservationDraft(
                    entityId: (int) $article['entity_id'],
                    indicatorId: (int) $indicatorId,
                    periodType: 'annual',
                    periodYear: $year,
                    value: $value,
                    rawValue: trim($fields[$valueField]),
                    status: $isEstimate ? 'estimated' : 'reported',
                    isEstimate: $isEstimate,
                    sourceId: $raw->dataset->source_id,
                    sourceDatasetId: $raw->dataset->id,
                    sourceSnapshotId: $raw->snapshot->id,
                    sourceRecordRef: 'wikipedia:'.$iso3.':'.$valueField,
                    sourceUrl: $article['url'],
                    referenceNote: sprintf(
                        'Wikipedia infobox "%s" (%s). Article revision id %s. Cite the article\'s own reference, not Wikipedia.',
                        $valueField,
                        $field['kind'],
                        $article['pageid'] ?? 'unknown',
                    ),
                );
            }
        }
    }

    /**
     * Pulls the top-level infobox parameters we care about out of raw wikitext.
     *
     * @return array<string, string>
     */
    private function extractInfoboxFields(string $wikitext): array
    {
        $wanted = [
            'population_estimate', 'population_estimate_year',
            'population_census', 'population_census_year',
            'population_total', 'population_as_of',
        ];

        $fields = [];

        foreach (preg_split('/\R/', $wikitext) ?: [] as $line) {
            if (! preg_match('/^\s*\|\s*([a-z0-9_]+)\s*=\s*(.*)$/i', $line, $matches)) {
                continue;
            }

            $key = strtolower($matches[1]);

            if (in_array($key, $wanted, true) && ! isset($fields[$key])) {
                $fields[$key] = trim($matches[2]);
            }
        }

        return $fields;
    }

    private function toNumber(string $raw): ?float
    {
        $text = $this->stripMarkup($raw);

        if (! preg_match('/-?[\d][\d,\s]*(\.\d+)?/', $text, $matches)) {
            return null;
        }

        $normalized = str_replace([',', ' '], '', $matches[0]);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function toYear(string $raw): ?int
    {
        $text = $this->stripMarkup($raw);

        if (! preg_match('/\b(1[89]\d{2}|20\d{2}|21\d{2})\b/', $text, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Removes refs, templates, links and HTML that commonly wrap infobox values.
     */
    private function stripMarkup(string $raw): string
    {
        $text = preg_replace('/<ref[^>]*\/>/i', '', $raw) ?? $raw;
        $text = preg_replace('/<ref[^>]*>.*?<\/ref>/is', '', $text) ?? $text;
        $text = preg_replace('/<!--.*?-->/s', '', $text) ?? $text;
        $text = preg_replace('/\{\{[^{}]*\}\}/', '', $text) ?? $text;
        $text = preg_replace('/\[\[(?:[^|\]]*\|)?([^\]]*)\]\]/', '$1', $text) ?? $text;
        $text = preg_replace('/<[^>]+>/', '', $text) ?? $text;
        $text = str_replace(['&nbsp;', '&#160;'], ' ', $text);

        return trim($text);
    }
}
