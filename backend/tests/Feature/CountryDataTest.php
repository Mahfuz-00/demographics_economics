<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The per-country "all data" table and the scrape-with-fallback action.
 */
class CountryDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Sanctum::actingAs(User::firstOrFail());
    }

    private function vitalsWikitext(): string
    {
        return <<<'WIKITEXT'
{| class="wikitable"
|+ Vital statistics
|-
! Year
! Mid-year population
! Births per year
! Deaths per year
! Crude birth rate<br />(per 1000)
! Total Fertility rate
|-
| 1950
| 346,278,817
| 15,287,998
| 7,852,219
| 44.15
| 5.73
|-
| 1951
| 353,870,058
| 15,663,440
| 7,973,004
| 44.26
| 5.77
|-
| 1952
| 361,874,904
| 16,082,888
| 8,048,990
| 44.44
| 5.82
|}
WIKITEXT;
    }

    public function test_all_data_table_is_empty_before_anything_is_ingested(): void
    {
        $this->getJson('/api/v1/entities/IND/observations')
            ->assertOk()
            ->assertJsonPath('meta.count', 0);
    }

    public function test_the_country_fetch_scrapes_vital_statistics_from_the_article(): void
    {
        Http::fake([
            'en.wikipedia.org/*' => Http::response([
                'parse' => [
                    'title' => 'Demographics of India',
                    'pageid' => 12345,
                    'wikitext' => $this->vitalsWikitext(),
                ],
            ]),
        ]);

        $response = $this->postJson('/api/v1/admin/entities/IND/fetch')->assertOk();

        // Four mapped indicators across three years. Total fertility rate is deliberately
        // not read from these tables: on the Japan and United States articles the column
        // lands on the crude death rate and produced "fertility" of 9.8 to 12.9. The World
        // Bank publishes TFR at a better rank, so it comes from there instead.
        $this->assertSame(12, $response->json('observations_created'));
        $this->assertFalse($response->json('searched_web'));

        $this->getJson('/api/v1/entities/IND/observations')
            ->assertOk()
            ->assertJsonPath('meta.count', 12);

        $this->getJson('/api/v1/entities/IND/observations?indicator=birth.count.annual')
            ->assertOk()
            ->assertJsonPath('meta.count', 3)
            ->assertJsonPath('data.0.indicator.code', 'birth.count.annual')
            ->assertJsonPath('data.0.period_year', 1950);

        // Vital statistics the module previously had no source for now exist.
        $this->getJson('/api/v1/series?entity=IND&indicator=birth.count.annual')
            ->assertOk()
            ->assertJsonPath('count', 3)
            ->assertJsonPath('points.0.value', 15287998);
    }

    public function test_the_country_year_matrix_accepts_a_comma_separated_indicator_list(): void
    {
        Http::fake([
            'en.wikipedia.org/*' => Http::response([
                'parse' => [
                    'title' => 'Demographics of India',
                    'pageid' => 12345,
                    'wikitext' => $this->vitalsWikitext(),
                ],
            ]),
        ]);

        $this->postJson('/api/v1/admin/entities/IND/fetch')->assertOk();

        // The country view draws only the handful of columns it needs, so the filter has
        // to take a list — the same convention the series endpoints use.
        $this->getJson('/api/v1/entities/IND/matrix?indicator=birth.count.annual,death.count.annual')
            ->assertOk()
            ->assertJsonCount(2, 'indicators')
            ->assertJsonPath('indicators.0.code', 'birth.count.annual')
            ->assertJsonPath('indicators.1.code', 'death.count.annual')
            ->assertJsonPath('meta.year_count', 3);

        // A list matching nothing yields an empty grid, not the entire table.
        $this->getJson('/api/v1/entities/IND/matrix?indicator=does.not.exist')
            ->assertOk()
            ->assertJsonPath('meta.indicator_count', 0)
            ->assertJsonPath('meta.year_count', 0);
    }

    public function test_the_fetch_requires_authentication(): void
    {
        // Drop the acting user established in setUp.
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/admin/entities/IND/fetch')->assertUnauthorized();
    }

    public function test_when_the_article_has_no_table_a_web_search_queues_candidates(): void
    {
        Http::fake([
            // Every candidate article title comes back without a usable table.
            'en.wikipedia.org/*' => Http::response([
                'error' => ['code' => 'missingtitle'],
            ]),
            'html.duckduckgo.com/*' => Http::response(
                '<html><body><div class="result">'
                .'<a class="result__a" href="//duckduckgo.com/l/?uddg='.rawurlencode('https://example.gov/statistics').'">Example statistics office</a>'
                .'<a class="result__snippet">Annual births and deaths.</a>'
                .'</div></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $response = $this->postJson('/api/v1/admin/entities/IND/fetch')->assertOk();

        $this->assertSame(0, $response->json('observations_created'));
        $this->assertTrue($response->json('searched_web'));
        $this->assertGreaterThan(0, count($response->json('candidates')));

        // Nothing found by search is stored as data; it waits for a decision.
        $this->getJson('/api/v1/entities/IND/observations')->assertOk()->assertJsonPath('meta.count', 0);
        $this->assertDatabaseHas('source_candidates', ['url' => 'https://example.gov/statistics', 'status' => 'pending']);
    }
}
