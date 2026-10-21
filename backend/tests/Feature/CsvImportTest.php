<?php

namespace Tests\Feature;

use App\Domain\Demographics\Import\CsvDemographicImporter;
use App\Domain\Demographics\Ingestion\ObservationWriter;
use App\Models\Entity;
use App\Models\Indicator;
use App\Models\IngestionBatch;
use App\Models\Observation;
use App\Models\Source;
use App\Models\SourceSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * End-to-end import of the hand-maintained spreadsheet.
 *
 * Each case re-imports the real file, so the assertions are grouped by concern
 * rather than split into one method per fact.
 */
class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function import(): IngestionBatch
    {
        return app(CsvDemographicImporter::class)->import(
            database_path('seed-data/Demographics(Demographic).csv')
        );
    }

    private function observation(string $iso3, string $indicatorCode, ?int $year = null): Observation
    {
        $entity = Entity::where('iso_alpha3', $iso3)->firstOrFail();
        $indicator = Indicator::where('code', $indicatorCode)->firstOrFail();

        return Observation::query()
            ->where('entity_id', $entity->id)
            ->where('indicator_id', $indicator->id)
            ->when($year !== null, fn ($query) => $query->where('period_year', $year))
            ->firstOrFail();
    }

    public function test_it_imports_the_sheet_and_keeps_the_bytes_it_read(): void
    {
        $batch = $this->import();

        $this->assertSame('success', $batch->status);
        $this->assertGreaterThan(5_000, $batch->observations_created);
        $this->assertNotNull($batch->file_hash);

        $snapshot = SourceSnapshot::firstOrFail();
        $this->assertSame($batch->file_hash, $snapshot->content_hash);
        $this->assertNotNull($snapshot->storage_path);

        // Every imported value is attributable and traceable.
        $manual = Source::where('code', 'manual_csv_v1')->firstOrFail();
        $this->assertSame(0, Observation::where('source_id', '!=', $manual->id)->count());
        $this->assertSame(0, Observation::whereNull('source_snapshot_id')->count());
        $this->assertSame(0, Observation::whereNull('retrieved_at')->count());
        $this->assertSame(0, Observation::whereNull('import_batch_id')->count());
    }

    public function test_an_import_that_throws_is_recorded_as_failed_not_left_running(): void
    {
        $this->mock(ObservationWriter::class)
            ->shouldReceive('write')
            ->andThrow(new RuntimeException('storage exploded'));

        try {
            app(CsvDemographicImporter::class)->import(
                database_path('seed-data/Demographics(Demographic).csv')
            );

            $this->fail('The exception should have propagated to the caller.');
        } catch (RuntimeException $error) {
            $this->assertSame('storage exploded', $error->getMessage());
        }

        // A batch stuck in `running` looks like work still in flight, so the admin view
        // can never tell a wedged import from a slow one. It has to say `failed`.
        $this->assertSame(0, IngestionBatch::where('status', 'running')->count());

        $failed = IngestionBatch::where('status', 'failed')->firstOrFail();

        $this->assertNotNull($failed->finished_at);
        $this->assertSame('storage exploded', $failed->report['error']);
    }

    public function test_values_match_the_source_including_its_long_run_history(): void
    {
        $this->import();

        $india = $this->observation('IND', 'pop.total', 2023);
        $this->assertSame(1438069596.0, $india->value);
        $this->assertStringContainsString('1,438,069,596', (string) $india->raw_value);

        // The peak is dated to the year it occurred, not to the snapshot year.
        $japanPeak = $this->observation('JPN', 'pop.peak');
        $this->assertSame(2008, $japanPeak->period_year);
        $this->assertSame(128083960.0, $japanPeak->value);

        // The source reaches back to the 1841 Irish census.
        $this->assertSame(1841, $this->observation('IRL', 'pop.peak')->period_year);

        // Aggregate rows resolve to aggregate entities.
        $world = Entity::where('slug', 'world')->firstOrFail();
        $this->assertGreaterThan(0, Observation::where('entity_id', $world->id)->count());
    }

    public function test_absent_and_unreadable_data_is_recorded_explicitly(): void
    {
        $batch = $this->import();

        // A blank cell in the source becomes a missing record, not a zero.
        $nativeShare = $this->observation('IND', 'pop.native_share');
        $this->assertNull($nativeShare->value);
        $this->assertSame('missing', $nativeShare->status);
        $this->assertSame('not_collected', $nativeShare->missing_reason);

        // Rows that could not be read at all are held for review, with the reason.
        $this->assertGreaterThan(0, $batch->quarantined);
        $this->assertDatabaseHas('ingestion_quarantine', ['reason_code' => 'broken_reference']);
        $this->assertDatabaseHas('ingestion_quarantine', ['reason_code' => 'unknown_entity']);
    }

    public function test_values_the_source_never_dates_are_kept_and_flagged_as_assumed(): void
    {
        $this->import();

        // Several tables in the sheet carry values but no reference year. Those used to
        // be quarantined and therefore invisible; they are now stored and marked.
        foreach ([
            'household.divorce_rate',
            'household.single_parent_share',
            'birth.nonmarital_share',
            'fert.childlessness',
            'pop.sex_workers.reported',
        ] as $code) {
            $indicator = Indicator::where('code', $code)->firstOrFail();
            $rows = Observation::where('indicator_id', $indicator->id);

            $this->assertGreaterThan(0, $rows->count(), "{$code} should hold data.");
            $this->assertGreaterThan(
                0,
                (clone $rows)->where('period_is_assumed', true)->count(),
                "{$code} values should be flagged as having an assumed period."
            );
        }

        // The flag is not applied wholesale: values that do state a year are unaffected.
        $this->assertGreaterThan(0, Observation::where('period_is_assumed', false)->count());
    }

    public function test_estimates_and_reported_values_are_not_interchangeable(): void
    {
        $this->import();

        // The source footnotes some figures with an asterisk; those are stored as
        // preliminary rather than as plain reported values, and both statuses exist
        // side by side rather than being flattened into one.
        $this->assertGreaterThan(0, Observation::where('status', 'preliminary')->count());
        $this->assertGreaterThan(0, Observation::where('status', 'reported')->count());

        // This source states no estimator qualifier on any value cell, so nothing
        // should be labelled an estimate here — the parser does not invent one.
        $this->assertSame(0, Observation::where('status', 'estimated')->count());
    }

    public function test_importing_the_same_file_again_changes_nothing(): void
    {
        $first = $this->import();
        $rowsAfterFirst = Observation::count();

        $second = $this->import();

        $this->assertGreaterThan(0, $rowsAfterFirst);

        // Re-importing must not add, revise or rewrite anything.
        $this->assertSame(0, $second->observations_created);
        $this->assertSame(0, $second->observations_superseded);
        $this->assertSame($rowsAfterFirst, Observation::count());

        // Every draft the second pass produced matched a row already held. It can
        // exceed the row count because the sheet repeats a handful of figures
        // (the same value appears in both the core and a right-hand table).
        $this->assertGreaterThanOrEqual($rowsAfterFirst, $second->observations_unchanged);
        $this->assertGreaterThanOrEqual($first->observations_created, $second->observations_unchanged);
    }
}
