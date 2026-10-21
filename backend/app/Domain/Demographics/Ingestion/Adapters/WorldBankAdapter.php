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
use RuntimeException;

/**
 * World Bank World Development Indicators.
 *
 * One request per indicator for every country at once, rather than one per country:
 * the API accepts `/country/all/indicator/{code}` and paginates, so ten requests
 * cover the whole world across sixty-plus years.
 *
 * The response is stored whole as a single snapshot; each observation points back
 * at it and carries a deep link to the World Bank's own page for that series.
 */
class WorldBankAdapter implements SourceAdapter
{
    /**
     * World Bank series code => our indicator code.
     */
    private const INDICATORS = [
        'SP.POP.TOTL' => 'pop.total',
        'SP.POP.65UP.TO.ZS' => 'pop.age_65plus_share',
        'SP.POP.GROW' => 'pop.growth_rate',
        'SP.DYN.TFRT.IN' => 'fert.tfr',
        'SP.DYN.CBRT.IN' => 'birth.rate.crude',
        'SP.DYN.CDRT.IN' => 'death.rate.crude',
        'SP.DYN.LE00.IN' => 'death.life_expectancy',
        'SP.DYN.IMRT.IN' => 'death.infant_rate',
        'SM.POP.NETM' => 'mig.net_annual',
        'NY.GDP.PCAP.CD' => 'economy.gdp.per_capita.nominal',
    ];

    public function __construct(private readonly SnapshotStore $snapshots) {}

    public function key(): string
    {
        return 'world_bank';
    }

    public function fetch(SourceDataset $dataset, array $options = []): RawDataset
    {
        $config = config('demographics.adapters.world_bank');
        $base = rtrim((string) $config['base_url'], '/');

        $payload = [];
        $lastStatus = null;

        foreach (array_keys(self::INDICATORS) as $seriesCode) {
            $page = 1;
            $bundles = [];

            do {
                $url = sprintf(
                    '%s/country/all/indicator/%s?format=json&per_page=%d&date=%d:%d&page=%d',
                    $base,
                    $seriesCode,
                    $config['per_page'],
                    $config['start_year'],
                    $config['end_year'],
                    $page,
                );

                $response = HttpClient::make()->get($url);
                $lastStatus = $response->status();

                if ($response->failed()) {
                    throw new RuntimeException(sprintf(
                        'World Bank request failed (%d) for series %s: %s',
                        $response->status(),
                        $seriesCode,
                        mb_substr($response->body(), 0, 300),
                    ));
                }

                $decoded = $response->json();
                $meta = $decoded[0] ?? [];
                $bundles = array_merge($bundles, $decoded[1] ?? []);

                $page++;
            } while ($page <= (int) ($meta['pages'] ?? 1) && $page <= 60);

            $payload[$seriesCode] = ['rows' => $bundles, 'count' => count($bundles)];
        }

        $contents = (string) json_encode($payload);

        $snapshot = $this->snapshots->store(
            $dataset,
            $contents,
            $base,
            $lastStatus,
            null,
            'application/json',
        );

        return new RawDataset(
            dataset: $dataset,
            snapshot: $snapshot,
            payload: $payload,
            meta: ['http_status' => $lastStatus, 'series' => count($payload)],
        );
    }

    public function normalize(RawDataset $raw): iterable
    {
        $entityIds = Entity::query()->whereNotNull('iso_alpha3')->pluck('id', 'iso_alpha3');
        $indicatorIds = Indicator::query()->pluck('id', 'code');

        foreach ($raw->payload as $seriesCode => $bundle) {
            $ourCode = self::INDICATORS[$seriesCode] ?? null;
            $indicatorId = $ourCode !== null ? $indicatorIds->get($ourCode) : null;

            if ($indicatorId === null) {
                continue;
            }

            foreach ($bundle['rows'] as $row) {
                $iso3 = $row['countryiso3code'] ?? null;
                $entityId = $iso3 !== null ? $entityIds->get($iso3) : null;
                $year = $row['date'] ?? null;
                $value = $row['value'] ?? null;

                // Aggregates (WLD, EUU, ...) have no matching entity and are skipped;
                // the module carries its own region entities.
                if ($entityId === null || ! is_numeric($year)) {
                    continue;
                }

                // The Bank simply omits years it has no figure for. Those absences are
                // reported as coverage gaps rather than written as 100k placeholder rows.
                if ($value === null || ! is_numeric($value)) {
                    continue;
                }

                yield new ObservationDraft(
                    entityId: $entityId,
                    indicatorId: $indicatorId,
                    periodType: 'annual',
                    periodYear: (int) $year,
                    value: (float) $value,
                    rawValue: (string) $value,
                    status: 'reported',
                    sourceId: $raw->dataset->source_id,
                    sourceDatasetId: $raw->dataset->id,
                    sourceSnapshotId: $raw->snapshot->id,
                    sourceRecordRef: 'wb:'.$seriesCode.':'.$iso3.':'.$year,
                    sourceUrl: 'https://data.worldbank.org/indicator/'.$seriesCode.'?locations='.$iso3,
                    referenceNote: 'World Bank WDI, series '.$seriesCode.'.',
                );
            }
        }
    }
}
