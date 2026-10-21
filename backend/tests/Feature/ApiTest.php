<?php

namespace Tests\Feature;

use App\Domain\Demographics\Ingestion\ConflictDetector;
use App\Domain\Demographics\Ingestion\Dto\ObservationDraft;
use App\Domain\Demographics\Ingestion\ObservationWriter;
use App\Models\Entity;
use App\Models\Indicator;
use App\Models\IngestionBatch;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private Entity $india;

    private Indicator $population;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->india = Entity::where('iso_alpha3', 'IND')->firstOrFail();
        $this->population = Indicator::where('code', 'pop.total')->firstOrFail();

        $batch = IngestionBatch::create(['kind' => 'api', 'status' => 'running', 'started_at' => now()]);
        $writer = new ObservationWriter(new ConflictDetector);

        $lowRank = Source::where('code', 'manual_csv_v1')->firstOrFail();
        $highRank = Source::where('code', 'world_bank')->firstOrFail();

        $writer->write($batch, [
            new ObservationDraft($this->india->id, $this->population->id, 'annual', 2019, value: 1_366_417_754, sourceId: $highRank->id),
            new ObservationDraft($this->india->id, $this->population->id, 'annual', 2020, value: 1_396_387_127, sourceId: $highRank->id),
        ]);

        // A second source that agrees on 2019 and disagrees on 2020.
        $writer->write($batch, [
            new ObservationDraft($this->india->id, $this->population->id, 'annual', 2019, value: 1_366_417_754, sourceId: $lowRank->id),
            new ObservationDraft($this->india->id, $this->population->id, 'annual', 2020, value: 1_380_000_000, sourceId: $lowRank->id),
        ]);
    }

    public function test_health_returns_the_stored_observation_count(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('observations', 4);
    }

    public function test_entities_endpoint_lists_countries_and_aggregates(): void
    {
        $response = $this->getJson('/api/v1/entities?countries_only=1')->assertOk();

        $this->assertGreaterThan(200, $response->json('meta.count'));
        $this->assertNotNull($response->json('data.0.iso_alpha3'));
    }

    public function test_an_entity_can_be_looked_up_by_iso_code(): void
    {
        $this->getJson('/api/v1/entities/IND')
            ->assertOk()
            ->assertJsonPath('data.name', 'India')
            ->assertJsonPath('data.iso_alpha3', 'IND');
    }

    public function test_indicators_endpoint_exposes_units_and_definitions(): void
    {
        $response = $this->getJson('/api/v1/indicators?module=demographics')->assertOk();

        $this->assertGreaterThan(20, $response->json('meta.count'));

        $codes = array_column($response->json('data'), 'code');
        $this->assertContains('pop.total', $codes);
        $this->assertNotContains('economy.entertainment_value', $codes);
    }

    public function test_series_returns_points_with_source_and_provenance(): void
    {
        $response = $this->getJson('/api/v1/series?entity=IND&indicator=pop.total')->assertOk();

        $this->assertSame(2, $response->json('count'));
        $this->assertSame('India', $response->json('entity.name'));
        $this->assertSame('persons', $response->json('indicator.unit'));

        $point = $response->json('points.0');
        $this->assertSame('2019', $point['period']);
        $this->assertSame('world_bank', $point['source']['code']);
        $this->assertStringContainsString('/observations/', $point['provenance_url']);
    }

    public function test_a_period_two_sources_disagree_on_is_flagged_but_the_alternative_is_returned(): void
    {
        $response = $this->getJson('/api/v1/series?entity=IND&indicator=pop.total')->assertOk();

        $this->assertSame(1, $response->json('conflicting_periods'));

        $disputed = collect($response->json('points'))->firstWhere('period', '2020');

        // The higher-ranked source is served...
        $this->assertSame('world_bank', $disputed['source']['code']);
        $this->assertTrue($disputed['has_conflict']);

        // ...and the other value is still handed over rather than hidden.
        $this->assertSame('manual_csv_v1', $disputed['other_sources'][0]['source']['code']);
        $this->assertFalse($disputed['other_sources'][0]['agrees']);
    }

    public function test_a_period_both_sources_agree_on_is_not_flagged_as_a_conflict(): void
    {
        $response = $this->getJson('/api/v1/series?entity=IND&indicator=pop.total')->assertOk();

        $agreed = collect($response->json('points'))->firstWhere('period', '2019');

        $this->assertFalse($agreed['has_conflict']);
        $this->assertTrue($agreed['other_sources'][0]['agrees']);
    }

    public function test_an_unknown_entity_is_a_404_not_an_empty_series(): void
    {
        $this->getJson('/api/v1/series?entity=ZZZ&indicator=pop.total')->assertNotFound();
    }

    public function test_latest_returns_the_most_recent_observation_per_series(): void
    {
        $this->getJson('/api/v1/series/latest?entities=IND,USA&indicators=pop.total')
            ->assertOk()
            ->assertJsonPath('data.0.latest.period', '2020');
    }

    public function test_compare_aligns_several_entities(): void
    {
        $response = $this->getJson('/api/v1/compare?entities=IND,USA&indicator=pop.total')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame('pop.total', $response->json('indicator.code'));
    }

    public function test_provenance_exposes_the_source_the_snapshot_and_the_revision_chain(): void
    {
        $uuid = $this->getJson('/api/v1/series?entity=IND&indicator=pop.total')
            ->json('points.0.observation_uuid');

        $this->getJson("/api/v1/observations/{$uuid}/provenance")
            ->assertOk()
            ->assertJsonPath('data.source.code', 'world_bank')
            ->assertJsonPath('data.entity.iso_alpha3', 'IND')
            ->assertJsonStructure(['data', 'snapshot', 'revisions' => ['this_is_current', 'superseded_by', 'replaced'], 'conflicts']);
    }

    public function test_coverage_reports_the_span_held_per_indicator(): void
    {
        $this->getJson('/api/v1/coverage?entity=IND')
            ->assertOk()
            ->assertJsonPath('data.0.indicator.code', 'pop.total')
            ->assertJsonPath('data.0.span.from', 2019)
            ->assertJsonPath('data.0.span.to', 2020);
    }

    public function test_conflicts_endpoint_lists_the_disagreement(): void
    {
        $this->getJson('/api/v1/conflicts')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.period', '2020');
    }

    public function test_sources_endpoint_ranks_sources(): void
    {
        $response = $this->getJson('/api/v1/sources')->assertOk();

        $ranks = array_column($response->json('data'), 'credibility_rank');
        $sorted = $ranks;
        sort($sorted);

        $this->assertSame($sorted, $ranks, 'Sources should be listed best-ranked first.');
    }

    public function test_admin_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/batches')->assertUnauthorized();
        $this->getJson('/api/v1/admin/quarantine')->assertUnauthorized();
    }

    public function test_an_unauthenticated_api_request_without_a_json_accept_header_still_gets_a_401(): void
    {
        // A plain client (curl without headers, a health probe) must get a 401 rather
        // than being redirected to a login route that does not exist.
        $this->get('/api/v1/admin/batches')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_an_authenticated_administrator_can_list_batches(): void
    {
        Sanctum::actingAs(User::firstOrFail());

        $this->getJson('/api/v1/admin/batches')->assertOk()->assertJsonStructure(['data']);
    }

    public function test_a_bad_login_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/token', [
            'email' => 'admin@example.com',
            'password' => 'not-the-password',
        ])->assertStatus(422);
    }

    public function test_a_valid_login_returns_a_token(): void
    {
        $this->postJson('/api/v1/auth/token', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->assertOk()->assertJsonStructure(['token', 'user']);
    }
}
