<?php

namespace Tests\Feature;

use App\Domain\Demographics\Curation\ObservationCurator;
use App\Domain\Demographics\Ingestion\ConflictDetector;
use App\Domain\Demographics\Ingestion\Dto\ObservationDraft;
use App\Domain\Demographics\Ingestion\ObservationWriter;
use App\Domain\Demographics\Query\SeriesReader;
use App\Models\Entity;
use App\Models\Indicator;
use App\Models\IngestionBatch;
use App\Models\Observation;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A correction must win on read, and must never destroy what it replaced.
 */
class CurationTest extends TestCase
{
    use RefreshDatabase;

    private Entity $india;

    private Indicator $population;

    private Observation $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->india = Entity::where('iso_alpha3', 'IND')->firstOrFail();
        $this->population = Indicator::where('code', 'pop.total')->firstOrFail();

        $batch = IngestionBatch::create(['kind' => 'api', 'status' => 'running', 'started_at' => now()]);
        $source = Source::where('code', 'world_bank')->firstOrFail();

        (new ObservationWriter(new ConflictDetector))->write($batch, [
            new ObservationDraft(
                $this->india->id,
                $this->population->id,
                'annual',
                2024,
                value: 1_450_935_791,
                sourceId: $source->id,
            ),
        ]);

        $this->original = Observation::firstOrFail();
    }

    public function test_a_correction_is_appended_as_a_curated_revision(): void
    {
        $curated = app(ObservationCurator::class)->curate($this->original, [
            'value' => 1_450_000_000,
            'note' => 'Corrected against the 2024 statistical yearbook.',
        ]);

        $this->assertTrue($curated->is_curated);
        $this->assertSame('manual_curation', $curated->source->code);
        $this->assertSame(1_450_000_000.0, $curated->value);
        $this->assertNotSame($this->original->uuid, $curated->uuid);

        // The value it replaced is still in the table, untouched.
        $this->assertDatabaseCount('observations', 2);
        $this->assertSame(1_450_935_791.0, $this->original->fresh()->value);
        $this->assertSame(1, Observation::where('is_curated', true)->count());
    }

    public function test_the_corrected_value_is_the_one_served(): void
    {
        app(ObservationCurator::class)->curate($this->original, ['value' => 1_450_000_000]);

        $series = app(SeriesReader::class)->series([$this->india->id], $this->population->id, [
            'frequency' => 'annual',
        ]);

        $period = $series[$this->india->id][0];

        $this->assertSame(1_450_000_000.0, $period['primary']->value);
        $this->assertTrue($period['primary']->is_curated);

        // The World Bank value is still handed over as the alternative.
        $this->assertSame(1_450_935_791.0, $period['alternatives'][0]->value);
    }

    public function test_a_correction_can_move_a_value_to_a_different_year(): void
    {
        app(ObservationCurator::class)->curate($this->original, ['value' => 1_450_000_000, 'period_year' => 2023]);

        $this->assertSame(1, Observation::where('period_year', 2023)->where('is_curated', true)->count());
        $this->assertSame(0, Observation::where('period_year', 2024)->where('is_curated', true)->count());
    }

    public function test_the_http_endpoint_requires_authentication(): void
    {
        $this->patchJson("/api/v1/admin/observations/{$this->original->uuid}", ['value' => 1])
            ->assertUnauthorized();
    }

    public function test_an_administrator_can_correct_a_value_through_the_api(): void
    {
        Sanctum::actingAs(User::firstOrFail());

        $this->patchJson("/api/v1/admin/observations/{$this->original->uuid}", [
            'value' => 1_450_000_000,
            'note' => 'Checked against the yearbook.',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_curated', true)
            ->assertJsonPath('data.value', 1450000000)
            ->assertJsonPath('superseded_observation_uuid', $this->original->uuid);
    }
}
