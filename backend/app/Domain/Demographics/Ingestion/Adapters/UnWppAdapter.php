<?php

namespace App\Domain\Demographics\Ingestion\Adapters;

use App\Domain\Demographics\Ingestion\Contracts\SourceAdapter;
use App\Domain\Demographics\Ingestion\Dto\ObservationDraft;
use App\Domain\Demographics\Ingestion\Dto\RawDataset;
use App\Domain\Demographics\Ingestion\Exceptions\SourceNotConfiguredException;
use App\Domain\Demographics\Ingestion\SnapshotStore;
use App\Models\Entity;
use App\Models\Indicator;
use App\Models\SourceDataset;
use App\Support\HttpClient;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

/**
 * UN World Population Prospects.
 *
 * Two access routes, in order of preference:
 *
 *  1. The UN Data Portal API, when `UN_DATAPORTAL_API_KEY` is set. Indicator and
 *     location ids are supplied through the dataset config, because the portal's
 *     ids are not derivable and must not be guessed.
 *  2. A bulk WPP CSV downloaded by hand into `storage/app/ingestion/un_wpp/`. The
 *     column names differ between WPP releases, so the mapping lives in the dataset
 *     config rather than in code.
 *
 * If neither is available the run fails loudly with instructions, so the scheduler
 * never looks like it is collecting UN data when it is not.
 *
 * Years up to the configured last-estimate year are recorded as estimates; later
 * years are recorded as projections and flagged, and the two are never mixed.
 */
class UnWppAdapter implements SourceAdapter
{
    public function __construct(private readonly SnapshotStore $snapshots) {}

    public function key(): string
    {
        return 'un_wpp';
    }

    public function fetch(SourceDataset $dataset, array $options = []): RawDataset
    {
        $apiKey = env((string) (config('demographics.adapters.un_wpp.api_key_env') ?? 'UN_DATAPORTAL_API_KEY'));

        if (! empty($apiKey)) {
            return $this->fetchFromApi($dataset, (string) $apiKey);
        }

        return $this->fetchFromBulkCsv($dataset);
    }

    private function fetchFromApi(SourceDataset $dataset, string $apiKey): RawDataset
    {
        $config = config('demographics.adapters.un_wpp');
        $mapping = $dataset->config['api']['indicators'] ?? [];

        if ($mapping === []) {
            throw new SourceNotConfiguredException(
                'UN WPP API mode needs dataset config "api.indicators" mapping portal indicator ids to indicator codes.'
            );
        }

        $base = rtrim((string) $config['portal_url'], '/');
        $rows = [];
        $lastStatus = null;

        foreach (array_keys($mapping) as $portalIndicatorId) {
            $page = 1;

            do {
                $response = HttpClient::make()->withToken($apiKey)
                    ->get($base.'/data/indicators/'.$portalIndicatorId, [
                        'pageNumber' => $page,
                        'pageSize' => 5000,
                    ]);

                $lastStatus = $response->status();

                if ($response->failed()) {
                    throw new SourceNotConfiguredException(sprintf(
                        'UN Data Portal returned %d for indicator %s: %s',
                        $response->status(),
                        $portalIndicatorId,
                        mb_substr($response->body(), 0, 300),
                    ));
                }

                $decoded = $response->json();
                $rows = array_merge($rows, $decoded['data'] ?? []);
                $page++;
            } while ($page <= (int) ($decoded['pages'] ?? 1) && $page <= 200);
        }

        $snapshot = $this->snapshots->store(
            $dataset,
            (string) json_encode($rows),
            $base,
            $lastStatus,
            null,
            'application/json',
        );

        return new RawDataset(
            dataset: $dataset,
            snapshot: $snapshot,
            payload: $rows,
            meta: ['http_status' => $lastStatus, 'rows' => count($rows), 'mode' => 'api'],
        );
    }

    private function fetchFromBulkCsv(SourceDataset $dataset): RawDataset
    {
        $csvConfig = $dataset->config['csv'] ?? null;

        if ($csvConfig === null) {
            throw new SourceNotConfiguredException(
                'UN WPP is not configured. Either set UN_DATAPORTAL_API_KEY and provide config "api.indicators", '
                .'or download a WPP bulk CSV into storage/app/ingestion/un_wpp/ and provide config "csv".'
            );
        }

        $disk = Storage::disk(config('demographics.snapshots.disk'));
        $directory = (string) ($csvConfig['directory'] ?? 'ingestion/un_wpp');
        $files = collect($disk->files($directory))->filter(
            fn (string $file): bool => str_ends_with(mb_strtolower($file), '.csv')
        );

        if ($files->isEmpty()) {
            throw new SourceNotConfiguredException(
                "No UN WPP CSV found in {$directory}. Download a WPP bulk file there, or configure the API instead."
            );
        }

        $rows = [];

        foreach ($files as $file) {
            $reader = Reader::createFromString((string) $disk->get($file));
            $reader->setHeaderOffset(0);

            foreach ($reader->getRecords() as $record) {
                $rows[] = $record;
            }
        }

        $snapshot = $this->snapshots->store(
            $dataset,
            (string) json_encode($rows),
            'file://'.$directory,
            null,
            null,
            'application/json',
        );

        return new RawDataset(
            dataset: $dataset,
            snapshot: $snapshot,
            payload: $rows,
            meta: ['rows' => count($rows), 'mode' => 'bulk_csv', 'files' => $files->count()],
        );
    }

    public function normalize(RawDataset $raw): iterable
    {
        $config = $datasetConfig = $raw->dataset->config;
        $isApi = ($raw->meta['mode'] ?? 'api') === 'api';

        $entityIds = Entity::query()->whereNotNull('iso_alpha3')->pluck('id', 'iso_alpha3');
        $indicatorIds = Indicator::query()->pluck('id', 'code');

        $lastEstimateYear = (int) ($config['csv']['last_estimate_year']
            ?? config('demographics.adapters.un_wpp.last_estimate_year', 2023));

        $mapping = $isApi
            ? ($config['api']['indicators'] ?? [])
            : [(string) ($config['csv']['indicator'] ?? 'pop.total') => $config['csv']['indicator'] ?? 'pop.total'];

        foreach ($raw->payload as $row) {
            $iso3 = $isApi
                ? ($row['locationIso3'] ?? null)
                : ($row[$config['csv']['iso_column'] ?? 'ISO3_code'] ?? null);

            $entityId = $iso3 !== null ? $entityIds->get((string) $iso3) : null;

            if ($entityId === null) {
                continue;
            }

            $ourCode = $this->resolveIndicatorCode($row, $mapping, $isApi, $datasetConfig);
            $indicatorId = $ourCode !== null ? $indicatorIds->get($ourCode) : null;

            if ($indicatorId === null) {
                continue;
            }

            $year = (int) ($isApi
                ? ($row['timeLabel'] ?? $row['time'] ?? 0)
                : ($row[$config['csv']['time_column'] ?? 'Time'] ?? 0));

            $value = $isApi
                ? ($row['value'] ?? null)
                : ($row[$config['csv']['value_column'] ?? 'TPopulation1July'] ?? null);

            if ($year === 0 || $value === null || ! is_numeric($value)) {
                continue;
            }

            $isProjection = $year > $lastEstimateYear;

            yield new ObservationDraft(
                entityId: (int) $entityId,
                indicatorId: (int) $indicatorId,
                periodType: 'annual',
                periodYear: $year,
                value: (float) $value,
                rawValue: (string) $value,
                status: $isProjection ? 'projected' : 'estimated',
                isEstimate: ! $isProjection,
                isProjection: $isProjection,
                sourceId: $raw->dataset->source_id,
                sourceDatasetId: $raw->dataset->id,
                sourceSnapshotId: $raw->snapshot->id,
                sourceRecordRef: 'unwpp:'.($iso3 ?? '?').':'.$year,
                sourceUrl: 'https://population.un.org/wpp/',
                referenceNote: $isProjection
                    ? 'UN WPP projection, not an observed figure.'
                    : 'UN WPP estimate.',
            );
        }
    }

    /**
     * In API mode the portal indicator id selects our indicator. In CSV mode the
     * mapping is a single indicator per file.
     */
    private function resolveIndicatorCode(array $row, array $mapping, bool $isApi, array $config): ?string
    {
        if (! $isApi) {
            return $mapping === [] ? null : (string) array_values($mapping)[0];
        }

        $portalId = (string) ($row['indicatorId'] ?? '');
        $variant = $row['variantLabel'] ?? $row['variant'] ?? null;

        if ($variant !== null && ($config['csv']['variant_value'] ?? null) !== null && $variant !== $config['csv']['variant_value']) {
            return null;
        }

        return $mapping[$portalId] ?? null;
    }
}
