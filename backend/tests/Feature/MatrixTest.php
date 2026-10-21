<?php

namespace Tests\Feature;

use App\Domain\Demographics\Ingestion\ConflictDetector;
use App\Domain\Demographics\Ingestion\Dto\ObservationDraft;
use App\Domain\Demographics\Ingestion\ObservationWriter;
use App\Models\Entity;
use App\Models\Indicator;
use App\Models\IngestionBatch;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two matrix views: countries × indicators (latest per country) and one country's
 * years × indicators.
 */
class MatrixTest extends TestCase
{
    use RefreshDatabase;

    private Entity $india;

    private Indicator $population;

    private Indicator $fertility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->india = Entity::where('iso_alpha3', 'IND')->firstOrFail();
        $this->population = Indicator::where('code', 'pop.total')->firstOrFail();
        $this->fertility = Indicator::where('code', 'fert.tfr')->firstOrFail();

        $batch = IngestionBatch::create(['kind' => 'api', 'status' => 'running', 'started_at' => now()]);
        $writer = new ObservationWriter(new ConflictDetector);

        $worldBank = Source::where('code', 'world_bank')->firstOrFail();
        $manual = Source::where('code', 'manual_csv_v1')->firstOrFail();

        $writer->write($batch, [
            new ObservationDraft($this->india->id, $this->population->id, 'annual', 2019, value: 1_366_417_754, sourceId: $worldBank->id),
            new ObservationDraft($this->india->id, $this->population->id, 'annual', 2020, value: 1_396_387_127, sourceId: $worldBank->id),
            new ObservationDraft($this->india->id, $this->fertility->id, 'annual', 2020, value: 1.97, sourceId: $worldBank->id),
        ]);

        // A lower-ranked source for the same 2020 population, plus a declared absence
        // for 2021 that must not be mistaken for the latest known value.
        $writer->write($batch, [
            new ObservationDraft($this->india->id, $this->population->id, 'annual', 2020, value: 1_380_000_000, sourceId: $manual->id),
            new ObservationDraft(
                $this->india->id,
                $this->population->id,
                'annual',
                2021,
                value: null,
                status: 'missing',
                missingReason: 'not_published',
                sourceId: $worldBank->id,
            ),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowFor(array $rows, string $iso3): array
    {
        foreach ($rows as $row) {
            if (($row['entity']['iso_alpha3'] ?? null) === $iso3) {
                return $row;
            }
        }

        $this->fail("No matrix row for {$iso3}.");
    }

    public function test_the_country_table_gives_each_country_one_row_of_latest_values(): void
    {
        $response = $this->getJson('/api/v1/matrix?indicators=pop.total,fert.tfr')->assertOk();

        // Every country appears, including those with no data yet.
        $this->assertGreaterThan(200, $response->json('meta.entity_count'));

        $values = $this->rowFor($response->json('rows'), 'IND')['values'];

        // Latest known value, not the 2021 absence.
        $this->assertSame(1_396_387_127, $values['pop.total']['value']);
        $this->assertSame('2020', $values['pop.total']['period']);

        // Served from the higher-ranked source.
        $this->assertSame('world_bank', $values['pop.total']['source']['code']);

        $this->assertSame(1.97, $values['fert.tfr']['value']);
    }

    public function test_aggregates_are_excluded_from_the_country_table_by_default(): void
    {
        $response = $this->getJson('/api/v1/matrix?indicators=pop.total')->assertOk();

        $types = array_column(array_column($response->json('rows'), 'entity'), 'entity_type');

        $this->assertNotContains('aggregate', $types);
        $this->assertNotContains('region', $types);
    }

    public function test_a_declared_absence_does_not_mask_the_last_known_value(): void
    {
        $rows = $this->getJson('/api/v1/matrix?indicators=pop.total')->json('rows');
        $values = $this->rowFor($rows, 'IND')['values'];

        $this->assertNotSame('2021', $values['pop.total']['period']);
        $this->assertNotNull($values['pop.total']['value']);
    }

    public function test_the_country_table_can_be_filtered_to_one_country(): void
    {
        $response = $this->getJson('/api/v1/matrix?indicators=pop.total&entities=IND')->assertOk();

        $this->assertSame('India', $response->json('rows.0.entity.name'));
    }

    public function test_the_year_table_has_one_row_per_year_newest_first(): void
    {
        $response = $this->getJson('/api/v1/entities/IND/matrix')->assertOk();

        $this->assertSame(3, $response->json('meta.year_count'));
        $this->assertSame('2021', $response->json('rows.0.period'));
        $this->assertSame('2020', $response->json('rows.1.period'));
        $this->assertSame('2019', $response->json('rows.2.period'));

        // 2020 carries both indicators across the row.
        $values2020 = $response->json('rows.1.values');
        $this->assertSame(1_396_387_127, $values2020['pop.total']['value']);
        $this->assertSame(1.97, $values2020['fert.tfr']['value']);

        // 2021 records the absence rather than dropping the year.
        $values2021 = $response->json('rows.0.values');
        $this->assertTrue($values2021['pop.total']['is_missing']);
        $this->assertNull($values2021['pop.total']['value']);
        $this->assertSame('not_published', $values2021['pop.total']['missing_reason']);
    }

    public function test_the_year_table_lists_indicators_with_their_units(): void
    {
        $response = $this->getJson('/api/v1/entities/IND/matrix')->assertOk();

        $codes = array_column($response->json('indicators'), 'code');

        $this->assertContains('pop.total', $codes);
        $this->assertContains('fert.tfr', $codes);

        // Same column order as the country table: the spreadsheet's sequence, not the
        // category tree. Present population precedes total fertility rate.
        $this->assertLessThan(
            array_search('fert.tfr', $codes, true),
            array_search('pop.total', $codes, true)
        );
    }

    public function test_projection_filtering_applies_to_both_views(): void
    {
        $this->getJson('/api/v1/matrix?indicators=pop.total&include_projections=0')
            ->assertOk()
            ->assertJsonPath('meta.include_projections', false);
    }

    public function test_columns_follow_the_source_spreadsheet_order(): void
    {
        $codes = array_column($this->getJson('/api/v1/matrix')->json('indicators'), 'code');

        // The sheet's sequence: peak population, present population, decline, migration,
        // fertility, births, deaths, age, life expectancy, per capita.
        $expected = [
            'pop.peak',
            'pop.total',
            'pop.decline.absolute',
            'pop.decline.percent',
            'pop.trend.annual',
            'pop.years.declining',
            'mig.immigrant_stock',
            'pop.native_share',
            'pop.diaspora',
            'fert.tfr',
            'birth.rate.crude',
            'death.rate.crude',
            'death.infant_rate',
            'pop.age_65plus_share',
            'pop.median_age',
            'death.life_expectancy',
            'economy.gdp.per_capita.nominal',
        ];

        $positions = [];

        foreach ($expected as $code) {
            $this->assertContains($code, $codes, "{$code} should be a column.");
            $positions[$code] = array_search($code, $codes, true);
        }

        $ordered = $positions;
        asort($ordered);

        $this->assertSame(array_keys($positions), array_keys($ordered), 'Columns should be in spreadsheet order.');

        // Indicators that are not columns in the sheet come after it.
        $this->assertGreaterThan(
            $positions['economy.gdp.per_capita.nominal'],
            array_search('birth.count.annual', $codes, true)
        );
    }

    public function test_an_unknown_entity_is_a_404(): void
    {
        $this->getJson('/api/v1/entities/ZZZ/matrix')->assertNotFound();
    }
}
