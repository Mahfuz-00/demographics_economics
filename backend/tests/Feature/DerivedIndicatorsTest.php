<?php

namespace Tests\Feature;

use App\Domain\Demographics\Derivation\DerivedIndicators;
use App\Domain\Demographics\Ingestion\Dto\ObservationDraft;
use App\Domain\Demographics\Ingestion\ObservationWriter;
use App\Models\Entity;
use App\Models\Indicator;
use App\Models\IngestionBatch;
use App\Models\Observation;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rates and flows that follow arithmetically from births, deaths and population.
 *
 * The point of these cases is the boundary: derivation fills gaps, and stops the moment
 * a source has published the figure itself.
 */
class DerivedIndicatorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function report(string $iso3, string $code, int $year, float $value): void
    {
        $entity = Entity::where('iso_alpha3', $iso3)->firstOrFail();
        $indicator = Indicator::where('code', $code)->firstOrFail();
        $source = Source::where('code', 'world_bank')->firstOrFail();

        $batch = IngestionBatch::create([
            'kind' => 'test',
            'status' => 'running',
            'started_at' => now(),
        ]);

        app(ObservationWriter::class)->write($batch, [
            new ObservationDraft(
                entityId: $entity->id,
                indicatorId: $indicator->id,
                periodType: 'annual',
                periodYear: $year,
                value: $value,
                unit: $indicator->unit,
                sourceId: $source->id,
            ),
        ]);
    }

    private function current(string $iso3, string $code, int $year): ?Observation
    {
        $entity = Entity::where('iso_alpha3', $iso3)->firstOrFail();
        $indicator = Indicator::where('code', $code)->firstOrFail();

        return Observation::query()
            ->current()
            ->where('entity_id', $entity->id)
            ->where('indicator_id', $indicator->id)
            ->where('period_year', $year)
            ->first();
    }

    private function value(string $iso3, string $code, int $year): ?float
    {
        return $this->current($iso3, $code, $year)?->value;
    }

    public function test_it_calculates_the_rates_a_source_omitted(): void
    {
        $this->report('IND', 'pop.total', 2020, 1_000_000);
        $this->report('IND', 'birth.count.annual', 2020, 20_000);
        $this->report('IND', 'death.count.annual', 2020, 8_000);

        app(DerivedIndicators::class)->run();

        $this->assertEqualsWithDelta(20.0, $this->value('IND', 'birth.rate.crude', 2020), 0.0001);
        $this->assertEqualsWithDelta(8.0, $this->value('IND', 'death.rate.crude', 2020), 0.0001);
        $this->assertEqualsWithDelta(12_000.0, $this->value('IND', 'pop.natural_change.absolute', 2020), 0.0001);
        $this->assertEqualsWithDelta(12.0, $this->value('IND', 'pop.natural_change.rate', 2020), 0.0001);
    }

    public function test_a_derived_value_is_flagged_and_attributed_to_no_source_document(): void
    {
        $this->report('IND', 'pop.total', 2020, 1_000_000);
        $this->report('IND', 'birth.count.annual', 2020, 20_000);

        app(DerivedIndicators::class)->run();

        $observation = $this->current('IND', 'birth.rate.crude', 2020);

        $this->assertNotNull($observation);
        $this->assertTrue($observation->is_derived);
        $this->assertSame('derived_calculations', $observation->source->code);
        $this->assertStringContainsString('Calculated, not reported', $observation->reference_note);
    }

    public function test_it_never_replaces_a_rate_a_source_published(): void
    {
        $this->report('IND', 'pop.total', 2020, 1_000_000);
        $this->report('IND', 'birth.count.annual', 2020, 20_000);

        // A source states the crude birth rate itself, and dissents from the arithmetic.
        $this->report('IND', 'birth.rate.crude', 2020, 19.4);

        app(DerivedIndicators::class)->run();

        $observation = $this->current('IND', 'birth.rate.crude', 2020);

        $this->assertNotNull($observation);
        $this->assertEqualsWithDelta(19.4, $observation->value, 0.0001);
        $this->assertFalse($observation->is_derived);
    }

    public function test_population_change_and_net_migration_need_consecutive_years(): void
    {
        foreach ([2020 => 1_000_000, 2021 => 1_020_000] as $year => $population) {
            $this->report('IND', 'pop.total', $year, $population);
            $this->report('IND', 'birth.count.annual', $year, 20_000);
            $this->report('IND', 'death.count.annual', $year, 8_000);
        }

        app(DerivedIndicators::class)->run();

        // 20,000 more people, 12,000 of that from natural increase, so 8,000 net arrivals.
        $this->assertEqualsWithDelta(20_000.0, $this->value('IND', 'pop.change.annual', 2021), 0.0001);
        $this->assertEqualsWithDelta(8_000.0, $this->value('IND', 'mig.net_annual', 2021), 0.0001);

        // The first year in the series has nothing before it to subtract.
        $this->assertNull($this->value('IND', 'pop.change.annual', 2020));
    }

    public function test_it_derives_nothing_when_an_input_is_missing(): void
    {
        // Births and deaths but no population: no denominator, so no rates.
        $this->report('IND', 'birth.count.annual', 2020, 20_000);
        $this->report('IND', 'death.count.annual', 2020, 8_000);

        app(DerivedIndicators::class)->run();

        $this->assertNull($this->current('IND', 'birth.rate.crude', 2020));
        $this->assertNull($this->current('IND', 'pop.natural_change.rate', 2020));

        // The absolute difference needs no denominator, so it is still available.
        $this->assertEqualsWithDelta(12_000.0, $this->value('IND', 'pop.natural_change.absolute', 2020), 0.0001);
    }

    public function test_running_twice_changes_nothing(): void
    {
        $this->report('IND', 'pop.total', 2020, 1_000_000);
        $this->report('IND', 'birth.count.annual', 2020, 20_000);

        app(DerivedIndicators::class)->run();
        $first = Observation::count();

        $second = app(DerivedIndicators::class)->run();

        $this->assertSame($first, Observation::count());
        $this->assertSame(0, $second['created']);
    }

    public function test_it_reconstructs_a_count_when_only_the_rate_is_published(): void
    {
        // This is the common case: sources publish births and deaths per 1,000 people,
        // not the absolute counts.
        $this->report('IND', 'pop.total', 2020, 1_000_000);
        $this->report('IND', 'birth.rate.crude', 2020, 25.0);

        app(DerivedIndicators::class)->run();

        $births = $this->current('IND', 'birth.count.annual', 2020);

        // 25 per 1,000 of a million people.
        $this->assertEqualsWithDelta(25_000.0, $births->value, 0.001);
        $this->assertTrue($births->is_derived);
        $this->assertSame('derived_calculations', $births->source->code);
        $this->assertStringContainsString('crude birth rate', $births->reference_note);
    }

    public function test_a_published_count_is_never_replaced_by_one_reconstructed_from_a_rate(): void
    {
        $this->report('IND', 'pop.total', 2020, 1_000_000);
        $this->report('IND', 'birth.rate.crude', 2020, 25.0);

        // The national office publishes its own count, which disagrees with the rate.
        $this->report('IND', 'birth.count.annual', 2020, 21_500);

        app(DerivedIndicators::class)->run();

        $entity = Entity::where('iso_alpha3', 'IND')->firstOrFail();
        $indicator = Indicator::where('code', 'birth.count.annual')->firstOrFail();

        $this->assertSame(1, Observation::query()
            ->current()
            ->where('entity_id', $entity->id)
            ->where('indicator_id', $indicator->id)
            ->where('period_year', 2020)
            ->count());

        $this->assertEqualsWithDelta(21_500.0, $this->current('IND', 'birth.count.annual', 2020)->value, 0.001);
    }

    public function test_a_reconstructed_count_carries_into_natural_change(): void
    {
        $this->report('IND', 'pop.total', 2020, 1_000_000);
        $this->report('IND', 'birth.rate.crude', 2020, 25.0);
        $this->report('IND', 'death.rate.crude', 2020, 10.0);

        app(DerivedIndicators::class)->run();

        // 25,000 births less 10,000 deaths, both reconstructed.
        $this->assertEqualsWithDelta(15_000.0, $this->value('IND', 'pop.natural_change.absolute', 2020), 0.001);
        $this->assertEqualsWithDelta(15.0, $this->value('IND', 'pop.natural_change.rate', 2020), 0.001);
    }

    public function test_a_peak_is_recomputed_from_the_series_and_replaces_a_stale_one(): void
    {
        $this->report('IND', 'pop.total', 2020, 1_000_000);
        $this->report('IND', 'pop.total', 2021, 1_200_000);

        // The spreadsheet's pre-computed peak, lower than a population we hold. A peak
        // below an observed value is stale arithmetic, not a competing opinion.
        $this->report('IND', 'pop.peak', 2010, 900_000);

        app(DerivedIndicators::class)->run();

        $peak = $this->current('IND', 'pop.peak', 2021);

        $this->assertNotNull($peak, 'The peak is dated to the year it occurred.');
        $this->assertEqualsWithDelta(1_200_000.0, $peak->value, 0.001);
        $this->assertTrue($peak->is_derived);

        // The superseded row is retained — it is still in the table, just not served.
        $indicatorId = Indicator::where('code', 'pop.peak')->value('id');

        $this->assertSame(1, Observation::where('indicator_id', $indicatorId)->where('is_derived', false)->count());
        $this->assertSame(0, Observation::query()->current()
            ->where('indicator_id', $indicatorId)
            ->where('is_derived', false)
            ->count());
    }

    public function test_a_derived_rate_is_superseded_when_a_source_later_publishes_one(): void
    {
        $this->report('IND', 'pop.total', 2020, 1_000_000);
        $this->report('IND', 'birth.count.annual', 2020, 20_000);

        app(DerivedIndicators::class)->run();

        // The UN now states the rate itself, at a better rank than a calculation.
        $entity = Entity::where('iso_alpha3', 'IND')->firstOrFail();
        $indicator = Indicator::where('code', 'birth.rate.crude')->firstOrFail();
        $un = Source::where('code', 'un_wpp')->firstOrFail();

        $batch = IngestionBatch::create(['kind' => 'test', 'status' => 'running', 'started_at' => now()]);

        app(ObservationWriter::class)->write($batch, [
            new ObservationDraft(
                entityId: $entity->id,
                indicatorId: $indicator->id,
                periodType: 'annual',
                periodYear: 2020,
                value: 19.8,
                unit: $indicator->unit,
                sourceId: $un->id,
            ),
        ]);

        // Both revisions are kept — nothing is thrown away — but the served value is
        // the published one, because a calculation ranks below every source.
        $served = Observation::query()
            ->current()
            ->where('observations.entity_id', $entity->id)
            ->where('observations.indicator_id', $indicator->id)
            ->where('observations.period_year', 2020)
            ->join('sources', 'sources.id', '=', 'observations.source_id')
            ->orderByDesc('observations.is_curated')
            ->orderBy('sources.credibility_rank')
            ->select('observations.*')
            ->first();

        $this->assertEqualsWithDelta(19.8, $served->value, 0.0001);
        $this->assertFalse($served->is_derived);

        $this->assertSame(2, Observation::query()
            ->current()
            ->where('entity_id', $entity->id)
            ->where('indicator_id', $indicator->id)
            ->where('period_year', 2020)
            ->count());
    }
}
