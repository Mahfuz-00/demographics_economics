<?php

namespace Tests\Feature;

use App\Domain\Demographics\Import\CsvDemographicImporter;
use App\Models\CsvColumnMapping;
use App\Models\Entity;
use App\Models\Indicator;
use App\Models\IngestionBatch;
use App\Models\Observation;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Economy, Debt and Wealth spreadsheets, imported through the same layout-driven
 * importer as the demographics sheet.
 *
 * Everything here is one row per country, so the assertions are about column mapping:
 * does each column land against the right indicator, with the right period, and does a
 * column that is not a country value stay out of the store?
 */
class ModuleSpreadsheetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function import(string $datasetCode, string $fileName): IngestionBatch
    {
        return app(CsvDemographicImporter::class)->import(
            database_path('seed-data/'.$fileName),
            datasetCode: $datasetCode,
        );
    }

    private function value(string $iso3, string $code, ?int $year = null): ?Observation
    {
        $entity = Entity::where('iso_alpha3', $iso3)->firstOrFail();
        $indicator = Indicator::where('code', $code)->firstOrFail();

        return Observation::query()
            ->where('entity_id', $entity->id)
            ->where('indicator_id', $indicator->id)
            ->when($year !== null, fn ($query) => $query->where('period_year', $year))
            ->first();
    }

    public function test_every_module_imports_through_the_same_importer(): void
    {
        $cases = [
            ['manual_economy_csv', 'Demographics(Economy).csv', 1_500],
            ['manual_debt_csv', 'Demographics(Debt).csv', 500],
            ['manual_indicator_csv', 'Demographics(Indicator).csv', 1_000],
        ];

        foreach ($cases as [$datasetCode, $fileName, $minimum]) {
            $batch = $this->import($datasetCode, $fileName);

            $this->assertSame('success', $batch->status, $datasetCode);
            $this->assertGreaterThan($minimum, $batch->observations_created, $datasetCode);
            $this->assertSame(0, $batch->quarantined, $datasetCode);
            $this->assertNotNull($batch->file_hash);
        }
    }

    public function test_economy_columns_land_against_their_own_indicator_and_year(): void
    {
        $this->import('manual_economy_csv', 'Demographics(Economy).csv');

        // Each paired "Year" column dates its own figure, not its neighbour's.
        $this->assertEqualsWithDelta(26_854.0, $this->value('USA', 'economy.gdp.nominal_bn', 2023)->value, 0.001);
        $this->assertEqualsWithDelta(20_807.0, $this->value('USA', 'economy.gdp.ppp_bn', 2020)->value, 0.001);
        $this->assertEqualsWithDelta(6_203.0, $this->value('JPN', 'economy.gdp.peak_bn', 2012)->value, 0.001);
        $this->assertEqualsWithDelta(34_268.0, $this->value('KOR', 'economy.gdp.per_capita.peak', 2019)->value, 0.001);

        // A column the sheet dates explicitly ("PC(2020)") is stored against that year.
        $this->assertEqualsWithDelta(63_051.0, $this->value('USA', 'economy.gdp.per_capita.nominal', 2020)->value, 0.001);
    }

    public function test_a_cell_that_leads_with_a_figure_keeps_the_qualifier_as_a_note(): void
    {
        $this->import('manual_economy_csv', 'Demographics(Economy).csv');

        $india = $this->value('IND', 'economy.informal_economy_share');

        $this->assertEqualsWithDelta(43.1, $india->value, 0.001);
        $this->assertStringContainsString('15-20', (string) $india->reference_note);
    }

    public function test_columns_that_are_not_country_values_are_not_stored(): void
    {
        $this->import('manual_economy_csv', 'Demographics(Economy).csv');

        // The sheet repeats global reference lists (oil-dependent, tax-haven and
        // tourist-dependent countries) on every line. Filing them against the country
        // on that line would be a fabrication, so they carry no indicator at all.
        $skipped = CsvColumnMapping::where('sub_table', 'economy_core')
            ->where('value_role', 'skip')
            ->pluck('csv_column')
            ->filter(fn (string $column) => $column !== '')
            ->values()
            ->all();

        $this->assertContains('Oil Dependent Countries(PC)', $skipped);
        $this->assertContains('Tax Haven Countries(PC)', $skipped);
        $this->assertContains('Tourist Dependent Countries', $skipped);

        $this->assertSame(
            0,
            CsvColumnMapping::where('sub_table', 'economy_core')->where('value_role', 'skip')->whereNotNull('indicator_id')->count()
        );
    }

    public function test_debt_columns_are_shares_of_gdp_and_are_dated_as_an_assumption(): void
    {
        $batch = $this->import('manual_debt_csv', 'Demographics(Debt).csv');

        $this->assertGreaterThan(0, $batch->report['assumed_periods']);

        $unitedStates = $this->value('USA', 'debt.total_pct_gdp');

        $this->assertEqualsWithDelta(374.0, $unitedStates->value, 0.001);
        $this->assertEqualsWithDelta(7.8, $this->value('USA', 'debt.student_loan_pct_gdp')->value, 0.001);

        // The sheet's own "Total Debt" column is the four sectors added up, which is what
        // confirms every one of them is a share of GDP rather than a currency amount.
        $sectors = ['debt.external_pct_gdp', 'debt.public_pct_gdp', 'debt.household_pct_gdp', 'debt.corporate_pct_gdp'];
        $sum = array_sum(array_map(fn (string $code) => $this->value('USA', $code)->value, $sectors));

        $this->assertEqualsWithDelta(374.0, $sum, 0.5);

        // The file states no year, so the value is dated to the compilation year and
        // flagged, rather than silently presented as a dated fact.
        $this->assertTrue($unitedStates->period_is_assumed);
        $this->assertSame((int) now()->year, $unitedStates->period_year);
    }

    public function test_indicator_sheet_keeps_its_euro_denominated_column(): void
    {
        $this->import('manual_indicator_csv', 'Demographics(Indicator).csv');

        $this->assertEqualsWithDelta(3_336.0, $this->value('CHN', 'external.forex_reserves_bn_usd')->value, 0.001);
        $this->assertEqualsWithDelta(2_244.40, $this->value('CHN', 'wealth.sovereign_fund_bn_usd')->value, 0.001);
        $this->assertEqualsWithDelta(1_596.0, $this->value('CHN', 'external.net_iip_bn_usd')->value, 0.001);

        $assets = $this->value('CHN', 'wealth.financial_assets_bn_eur');

        $this->assertEqualsWithDelta(22_469.0, $assets->value, 0.001);
        $this->assertSame('currency_eur_bn', $assets->unit);
    }

    public function test_re_importing_an_unchanged_sheet_changes_nothing(): void
    {
        foreach ([
            ['manual_economy_csv', 'Demographics(Economy).csv'],
            ['manual_debt_csv', 'Demographics(Debt).csv'],
            ['manual_indicator_csv', 'Demographics(Indicator).csv'],
        ] as [$datasetCode, $fileName]) {
            $this->import($datasetCode, $fileName);
            $count = Observation::count();

            $second = $this->import($datasetCode, $fileName);

            $this->assertSame(0, $second->observations_created, $datasetCode);
            $this->assertSame($count, Observation::count(), $datasetCode);
        }
    }

    public function test_a_module_indicator_is_marked_with_its_own_module(): void
    {
        $this->assertSame('debt', Indicator::where('code', 'debt.total_pct_gdp')->firstOrFail()->module);
        $this->assertSame('economy', Indicator::where('code', 'economy.gdp.ppp_bn')->firstOrFail()->module);
        $this->assertSame('wealth', Indicator::where('code', 'external.forex_reserves_bn_usd')->firstOrFail()->module);
    }
}
