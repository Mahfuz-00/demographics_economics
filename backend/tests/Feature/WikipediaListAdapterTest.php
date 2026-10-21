<?php

namespace Tests\Feature;

use App\Domain\Demographics\Ingestion\Adapters\WikipediaListAdapter;
use App\Domain\Demographics\Ingestion\Dto\ObservationDraft;
use App\Models\Entity;
use App\Models\Source;
use App\Models\SourceDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Economy, Debt and Wealth modules are published as Wikipedia "list of countries
 * by ..." pages rather than an article per country.
 *
 * These pages disagree with each other about their own shape, so the fixtures here
 * reproduce the shapes actually observed: a publisher per column with the vintage in
 * the header, values in millions, a table with a year column, and a table with neither.
 * The failure to design against is a plausible-looking wrong number, not an empty one.
 */
class WikipediaListAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * @param  array<string, mixed>  $pages
     */
    private function dataset(array $pages): SourceDataset
    {
        return SourceDataset::create([
            'source_id' => Source::where('code', 'wikipedia')->firstOrFail()->id,
            'code' => 'test_list_dataset',
            'name' => 'Test list dataset',
            'adapter_key' => 'wikipedia_list',
            'access_method' => 'wikitext',
            'update_frequency' => 'monthly',
            'config' => ['pages' => $pages],
        ]);
    }

    /**
     * @return array<int, ObservationDraft>
     */
    private function drafts(SourceDataset $dataset): array
    {
        $adapter = app(WikipediaListAdapter::class);
        $raw = $adapter->fetch($dataset);

        $drafts = iterator_to_array($adapter->normalize($raw));

        // Read the meta only after normalising: the adapter annotates it while it runs.
        $this->lastMeta = $raw->meta;

        return $drafts;
    }

    private array $lastMeta = [];

    private function fakePage(string $wikitext, string $timestamp = '2025-06-01T00:00:00Z'): void
    {
        Http::fake([
            '*' => Http::response([
                'query' => [
                    'pages' => [[
                        'pageid' => 4242,
                        'title' => 'Some list page',
                        'revisions' => [[
                            'revid' => 999,
                            'timestamp' => $timestamp,
                            'slots' => ['main' => ['content' => $wikitext]],
                        ]],
                    ]],
                ],
            ]),
        ]);
    }

    private function gdpPage(): string
    {
        return <<<'WIKITEXT'
{| class="wikitable sortable static-row-numbers"
|- class="static-row-header"
! Country/Territory
! [[International Monetary Fund|IMF]] (2026)
! [[World Bank]] (2025)
|-
| [[United States]] || 30,337,162 || 29,184,890
|-
| [[China]] || 19,534,894 || 18,273,357
|-
| {{flagcountry|India}} || 4,187,000 || 4,001,000
|}
WIKITEXT;
    }

    private function observationFor(array $drafts, string $iso3, string $code, int $year): ?ObservationDraft
    {
        $entityId = Entity::where('iso_alpha3', $iso3)->value('id');
        $indicatorId = (new \App\Models\Indicator)->newQuery()->where('code', $code)->value('id');

        foreach ($drafts as $draft) {
            if ($draft->entityId === $entityId && $draft->indicatorId === $indicatorId && $draft->periodYear === $year) {
                return $draft;
            }
        }

        return null;
    }

    public function test_a_publisher_column_is_dated_from_its_own_header_and_rescaled(): void
    {
        $this->fakePage($this->gdpPage());

        $dataset = $this->dataset([[
            'key' => 'gdp_nominal',
            'titles' => ['List of countries by GDP (nominal)'],
            'readable' => true,
            'entity_headers' => ['country'],
            'period' => 'header_year',
            'columns' => [
                'imf' => ['indicator' => 'economy.gdp.nominal_bn', 'scale' => 0.001],
                'world bank' => ['indicator' => 'economy.gdp.nominal_bn', 'scale' => 0.001],
            ],
        ]]);

        $drafts = $this->drafts($dataset);

        // The page publishes millions of US dollars: 30,337,162 million is 30,337.162bn.
        $imf = $this->observationFor($drafts, 'USA', 'economy.gdp.nominal_bn', 2026);

        $this->assertNotNull($imf, 'The IMF column header dates the value 2026.');
        $this->assertEqualsWithDelta(30_337.162, $imf->value, 0.001);

        // The World Bank column carries a different vintage, so it is a separate
        // observation for the same country rather than a competing value for one year.
        $worldBank = $this->observationFor($drafts, 'USA', 'economy.gdp.nominal_bn', 2025);

        $this->assertNotNull($worldBank);
        $this->assertEqualsWithDelta(29_184.89, $worldBank->value, 0.001);

        $this->assertFalse($imf->periodIsAssumed);
    }

    public function test_a_flag_template_country_cell_is_resolved_to_a_country(): void
    {
        $this->fakePage($this->gdpPage());

        $dataset = $this->dataset([[
            'key' => 'gdp_nominal',
            'titles' => ['List of countries by GDP (nominal)'],
            'readable' => true,
            'entity_headers' => ['country'],
            'period' => 'header_year',
            'columns' => ['imf' => ['indicator' => 'economy.gdp.nominal_bn', 'scale' => 0.001]],
        ]]);

        $drafts = $this->drafts($dataset);

        // {{flagcountry|India}} is markup, not a name.
        $this->assertNotNull($this->observationFor($drafts, 'IND', 'economy.gdp.nominal_bn', 2026));
    }

    public function test_a_page_that_states_no_period_is_dated_to_the_revision_and_flagged(): void
    {
        $this->fakePage(<<<'WIKITEXT'
{| class="wikitable sortable"
! Country
! Total debt (billions)
! % of GDP
|-
| [[Japan]] || 4,438 || 249.7
|}
WIKITEXT, '2025-06-01T00:00:00Z');

        $dataset = $this->dataset([[
            'key' => 'external_debt',
            'titles' => ['List of countries by external debt'],
            'readable' => true,
            'entity_headers' => ['country'],
            'period' => 'revision',
            'columns' => [
                'total debt (billions)' => ['indicator' => 'debt.external_bn_usd', 'scale' => 1.0],
                '% of gdp' => ['indicator' => 'debt.external_pct_gdp', 'scale' => 1.0],
            ],
        ]]);

        $drafts = $this->drafts($dataset);

        $debt = $this->observationFor($drafts, 'JPN', 'debt.external_bn_usd', 2025);

        $this->assertNotNull($debt);
        $this->assertEqualsWithDelta(4_438.0, $debt->value, 0.001);

        // The page states no year, so the value is as-of the revision and says so.
        $this->assertTrue($debt->periodIsAssumed);
        $this->assertStringContainsString('assumed', (string) $debt->referenceNote);

        // The share column lands on the share indicator, not the absolute one.
        $share = $this->observationFor($drafts, 'JPN', 'debt.external_pct_gdp', 2025);

        $this->assertNotNull($share);
        $this->assertEqualsWithDelta(249.7, $share->value, 0.001);
    }

    public function test_a_page_that_is_not_configured_is_skipped_without_being_fetched(): void
    {
        Http::fake(['*' => Http::response(['query' => ['pages' => []]])]);

        $dataset = $this->dataset([[
            'key' => 'household_debt',
            'titles' => ['List of countries by household debt'],
            'readable' => false,
            'reason' => 'quarterly, not annual',
        ]]);

        $adapter = app(WikipediaListAdapter::class);
        $raw = $adapter->fetch($dataset);

        $this->assertSame([], iterator_to_array($adapter->normalize($raw)));
        $this->assertSame(0, $raw->meta['pages_fetched']);
        $this->assertSame('quarterly, not annual', $raw->meta['pages_skipped'][0]['reason']);

        // Nothing was requested: a page that cannot be read correctly is not read at all.
        Http::assertNothingSent();
    }

    public function test_headers_that_are_not_configured_are_reported_rather_than_guessed_at(): void
    {
        $this->fakePage(<<<'WIKITEXT'
{| class="wikitable sortable"
! Country
! Total wealth (USD bn)
! Mystery column
! % of world
|-
| [[France]] || 16,235 || 999 || 3.6
|}
WIKITEXT);

        $dataset = $this->dataset([[
            'key' => 'total_wealth',
            'titles' => ['List of countries by total private wealth'],
            'readable' => true,
            'entity_headers' => ['country'],
            'period' => 'revision',
            'skip' => ['% of'],
            'columns' => ['total wealth (usd bn)' => ['indicator' => 'wealth.current_bn_usd', 'scale' => 1.0]],
        ]]);

        $drafts = $this->drafts($dataset);

        $this->assertEqualsWithDelta(16_235.0, $this->observationFor($drafts, 'FRA', 'wealth.current_bn_usd', 2025)->value, 0.001);

        // "Mystery column" is neither configured nor skipped, so it is surfaced for a
        // person to pin rather than quietly matched to something.
        $this->assertContains('Mystery column', $this->lastMeta['pages']['total_wealth']['unmatched']);

        // "% of world" was declared skippable and is not reported as unmatched.
        $this->assertNotContains('% of world', $this->lastMeta['pages']['total_wealth']['unmatched']);
    }

    public function test_an_implausible_value_is_dropped_and_counted(): void
    {
        $this->fakePage(<<<'WIKITEXT'
{| class="wikitable sortable"
! Country
! % of GDP
|-
| [[Japan]] || 49.7
|-
| [[Nauru]] || 99999
|}
WIKITEXT);

        $dataset = $this->dataset([[
            'key' => 'external_debt',
            'titles' => ['List of countries by external debt'],
            'readable' => true,
            'entity_headers' => ['country'],
            'period' => 'revision',
            'columns' => ['% of gdp' => ['indicator' => 'debt.external_pct_gdp', 'scale' => 1.0]],
        ]]);

        $this->drafts($dataset);

        // 99,999% of GDP is not a debt ratio; the sanity bounds hold it back.
        $this->assertSame(1, $this->lastMeta['implausible_values']);
    }

    public function test_the_seeded_module_datasets_register_the_list_pages(): void
    {
        foreach (['wikipedia_economy_lists', 'wikipedia_debt_lists', 'wikipedia_wealth_lists'] as $code) {
            $dataset = SourceDataset::where('code', $code)->firstOrFail();

            $this->assertSame('wikipedia_list', $dataset->adapter_key);
            $this->assertNotEmpty($dataset->config['pages'], $code);

            foreach ($dataset->config['pages'] as $page) {
                $this->assertArrayHasKey('titles', $page);
                $this->assertArrayHasKey('readable', $page);

                // A page that is not read must say why, so the registry cannot quietly
                // carry a page that never contributes anything.
                if ($page['readable'] === false) {
                    $this->assertNotEmpty($page['reason'] ?? null, $page['key']);
                } else {
                    $this->assertNotEmpty($page['columns'] ?? [], $page['key']);
                }
            }
        }
    }
}
