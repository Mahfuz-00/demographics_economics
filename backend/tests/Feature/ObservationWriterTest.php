<?php

namespace Tests\Feature;

use App\Domain\Demographics\Ingestion\ConflictDetector;
use App\Domain\Demographics\Ingestion\Dto\ObservationDraft;
use App\Domain\Demographics\Ingestion\ObservationWriter;
use App\Models\Entity;
use App\Models\Indicator;
use App\Models\IngestionBatch;
use App\Models\IngestionQuarantine;
use App\Models\Observation;
use App\Models\ObservationConflict;
use App\Models\ObservationSupersession;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The guarantee these tests defend: observations are never overwritten, identical
 * data is never duplicated, and disagreement between sources is recorded rather
 * than silently resolved.
 */
class ObservationWriterTest extends TestCase
{
    use RefreshDatabase;

    private ObservationWriter $writer;

    private Entity $entity;

    private Indicator $indicator;

    private Source $highRankSource;

    private Source $lowRankSource;

    private IngestionBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->writer = new ObservationWriter(new ConflictDetector);
        $this->entity = Entity::where('iso_alpha3', 'IND')->firstOrFail();
        $this->indicator = Indicator::where('code', 'pop.total')->firstOrFail();
        $this->highRankSource = Source::where('code', 'world_bank')->firstOrFail();   // rank 3
        $this->lowRankSource = Source::where('code', 'manual_csv_v1')->firstOrFail(); // rank 5

        $this->batch = IngestionBatch::create([
            'kind' => 'api',
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    private function draft(
        float $value,
        int $sourceId,
        int $year = 2020,
        string $periodType = 'annual',
        ?int $quarter = null,
        ?int $month = null,
    ): ObservationDraft {
        return new ObservationDraft(
            entityId: $this->entity->id,
            indicatorId: $this->indicator->id,
            periodType: $periodType,
            periodYear: $year,
            periodQuarter: $quarter,
            periodMonth: $month,
            value: $value,
            unit: 'persons',
            sourceId: $sourceId,
            sourceDatasetId: null,
        );
    }

    public function test_it_stores_a_new_observation(): void
    {
        $result = $this->writer->write($this->batch, [$this->draft(1_400_000_000, $this->highRankSource->id)]);

        $this->assertSame(1, $result->created);
        $this->assertSame(0, $result->unchanged);
        $this->assertDatabaseCount('observations', 1);
        $this->assertSame(1, Observation::first()->revision);
    }

    public function test_ingesting_the_same_value_again_changes_nothing(): void
    {
        $this->writer->write($this->batch, [$this->draft(1_400_000_000, $this->highRankSource->id)]);
        $result = $this->writer->write($this->batch, [$this->draft(1_400_000_000, $this->highRankSource->id)]);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->unchanged);
        $this->assertDatabaseCount('observations', 1);
    }

    public function test_a_changed_value_becomes_a_new_revision_and_the_old_row_survives(): void
    {
        $this->writer->write($this->batch, [$this->draft(1_400_000_000, $this->highRankSource->id)]);
        $original = Observation::firstOrFail();

        $result = $this->writer->write($this->batch, [$this->draft(1_410_000_000, $this->highRankSource->id)]);

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->superseded);

        // Both rows exist; the earlier value was not overwritten.
        $this->assertDatabaseCount('observations', 2);
        $this->assertSame(1_400_000_000.0, $original->fresh()->value);

        $replacement = Observation::where('revision', 2)->firstOrFail();
        $this->assertSame(1_410_000_000.0, $replacement->value);
        $this->assertSame($original->id, ObservationSupersession::firstOrFail()->superseded_id);
        $this->assertSame($replacement->id, ObservationSupersession::firstOrFail()->superseded_by_id);
    }

    public function test_the_current_scope_hides_superseded_rows_but_the_table_keeps_them(): void
    {
        $this->writer->write($this->batch, [$this->draft(1_400_000_000, $this->highRankSource->id)]);
        $this->writer->write($this->batch, [$this->draft(1_410_000_000, $this->highRankSource->id)]);

        // The table keeps both revisions; only the latest one is served.
        $this->assertSame(2, DB::table('observations')->count());
        $this->assertSame(1, Observation::query()->current()->count());
        $this->assertSame(1_410_000_000.0, (float) Observation::query()->current()->value('value'));
    }

    public function test_a_disagreement_between_sources_is_recorded_and_ranked(): void
    {
        $this->writer->write($this->batch, [$this->draft(340_110_988, $this->lowRankSource->id)]);
        $result = $this->writer->write($this->batch, [$this->draft(340_003_797, $this->highRankSource->id)]);

        $this->assertSame(1, $result->conflicts);

        $conflict = ObservationConflict::firstOrFail();

        // The higher-ranked source is primary; both values remain stored.
        $this->assertSame($this->highRankSource->id, $conflict->primaryObservation->source_id);
        $this->assertSame($this->lowRankSource->id, $conflict->conflictingObservation->source_id);
        $this->assertDatabaseCount('observations', 2);

        // 107,191 apart on a base of 340 million is a rounding-level disagreement.
        $this->assertSame('minor', $conflict->significance);
    }

    public function test_identical_values_from_two_sources_are_not_a_conflict(): void
    {
        $this->writer->write($this->batch, [$this->draft(1_438_069_596, $this->lowRankSource->id)]);
        $result = $this->writer->write($this->batch, [$this->draft(1_438_069_596, $this->highRankSource->id)]);

        $this->assertSame(0, $result->conflicts);
        $this->assertDatabaseCount('observations', 2);
    }

    public function test_a_sub_annual_value_is_refused_for_an_annual_only_indicator(): void
    {
        $draft = $this->draft(
            value: 350_000_000,
            sourceId: $this->highRankSource->id,
            year: 2020,
            periodType: 'quarterly',
            quarter: 1,
        );

        $result = $this->writer->write($this->batch, [$draft]);

        $this->assertSame(1, $result->rejected);
        $this->assertSame(0, $result->created);
        $this->assertDatabaseCount('observations', 0);
        $this->assertSame('unsupported_frequency', IngestionQuarantine::firstOrFail()->reason_code);
    }

    public function test_a_missing_value_is_stored_explicitly_with_its_reason(): void
    {
        $draft = new ObservationDraft(
            entityId: $this->entity->id,
            indicatorId: $this->indicator->id,
            periodType: 'annual',
            periodYear: 2021,
            value: null,
            status: 'missing',
            missingReason: 'not_collected',
            sourceId: $this->lowRankSource->id,
        );

        $this->writer->write($this->batch, [$draft]);

        $stored = Observation::firstOrFail();
        $this->assertNull($stored->value);
        $this->assertSame('missing', $stored->status);
        $this->assertSame('not_collected', $stored->missing_reason);
        $this->assertTrue($stored->isMissing());
    }
}
