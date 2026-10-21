<?php

namespace Database\Seeders;

use App\Models\Source;
use App\Models\SourceDataset;
use Illuminate\Database\Seeder;

class SourceSeeder extends Seeder
{
    public function run(): void
    {
        $config = config('demographics');

        $sources = [
            [
                'code' => 'un_wpp',
                'publisher' => 'United Nations, Department of Economic and Social Affairs, Population Division',
                'publisher_short' => 'UN DESA',
                'source_type' => 'un',
                'homepage_url' => 'https://population.un.org/wpp/',
                'license' => 'UN terms of use',
                'license_url' => 'https://www.un.org/en/about-us/terms-of-use',
                'terms_note' => 'World Population Prospects. Estimates up to the last estimate year; later years are projections and are flagged as such.',
                'datasets' => [
                    [
                        'code' => 'un_wpp_population',
                        'name' => 'World Population Prospects — standard indicators',
                        'description' => 'Annual population, births, deaths, net migration, fertility, life expectancy and median age by country, 1950-2100.',
                        'access_method' => 'csv_bulk_zip',
                        'endpoint_url' => $config['adapters']['un_wpp']['portal_url'],
                        'documentation_url' => 'https://population.un.org/dataportalapi/index.html',
                        'auth_type' => 'none',
                        'update_frequency' => 'annual',
                        'expected_latency_days' => 365,
                        'adapter_key' => 'un_wpp',
                    ],
                ],
            ],
            [
                'code' => 'world_bank',
                'publisher' => 'World Bank',
                'publisher_short' => 'World Bank',
                'source_type' => 'world_bank',
                'homepage_url' => 'https://data.worldbank.org/',
                'license' => 'CC BY 4.0',
                'license_url' => 'https://datacatalog.worldbank.org/public-licenses',
                'terms_note' => 'World Development Indicators. Compiles national sources and UN estimates; individual series carry their own source notes.',
                'datasets' => [
                    [
                        'code' => 'world_bank_wdi_demographics',
                        'name' => 'World Development Indicators — demographic series',
                        'description' => 'Population, age structure, fertility, mortality and net migration by country, 1960 to present.',
                        'access_method' => 'api_json',
                        'endpoint_url' => $config['adapters']['world_bank']['base_url'],
                        'documentation_url' => 'https://datahelpdesk.worldbank.org/knowledgebase/articles/889392',
                        'auth_type' => 'none',
                        'update_frequency' => 'weekly',
                        'expected_latency_days' => 30,
                        'adapter_key' => 'world_bank',
                    ],
                ],
            ],
            [
                'code' => 'wikipedia',
                'publisher' => 'Wikipedia contributors',
                'publisher_short' => 'Wikipedia',
                'source_type' => 'wikipedia',
                'homepage_url' => 'https://en.wikipedia.org/',
                'license' => 'CC BY-SA 4.0',
                'license_url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
                'terms_note' => 'Used only for recent published figures and to surface the citation trail. Never supersedes national or UN data.',
                'datasets' => [
                    [
                        'code' => 'wikipedia_country_infobox',
                        'name' => 'Wikipedia country infobox population figures',
                        'description' => 'Population estimate and census values from country article infoboxes, with the cited source.',
                        'access_method' => 'wikitext',
                        'endpoint_url' => $config['adapters']['wikipedia']['api_url'],
                        'documentation_url' => 'https://www.mediawiki.org/wiki/API:Parsing_wikitext',
                        'auth_type' => 'none',
                        'update_frequency' => 'monthly',
                        'expected_latency_days' => 30,
                        'adapter_key' => 'wikipedia',
                    ],
                    [
                        'code' => 'wikipedia_vital_statistics',
                        'name' => 'Wikipedia "Demographics of <country>" vital statistics',
                        'description' => 'The births, deaths, natural change, crude rates, total fertility, infant mortality and life expectancy tables on each country\'s "Demographics of ..." article. Usually compiled from national statistics offices or UN WPP, but as a secondary source: it is ranked below everything official.',
                        'access_method' => 'wikitext',
                        'endpoint_url' => $config['adapters']['wikipedia']['api_url'],
                        'documentation_url' => 'https://www.mediawiki.org/wiki/API:Parsing_wikitext',
                        'auth_type' => 'none',
                        'update_frequency' => 'monthly',
                        'expected_latency_days' => 30,
                        'adapter_key' => 'wikipedia_vital_statistics',
                    ],
                    [
                        'code' => 'wikipedia_economy_lists',
                        'name' => 'Wikipedia "List of countries by ..." — GDP',
                        'description' => 'The GDP (nominal) and GDP per capita list pages. Each page stacks one column per publishing body (IMF, World Bank, United Nations); each column states its own vintage in its header, so a country gets one value per publisher rather than one merged figure.',
                        'access_method' => 'wikitext',
                        'endpoint_url' => $config['adapters']['wikipedia']['api_url'],
                        'documentation_url' => 'https://www.mediawiki.org/wiki/API:Parsing_wikitext',
                        'auth_type' => 'none',
                        'update_frequency' => 'monthly',
                        'expected_latency_days' => 30,
                        'adapter_key' => 'wikipedia_list',
                        'config' => [
                            'pages' => [
                                [
                                    'key' => 'gdp_nominal',
                                    'titles' => ['List of countries by GDP (nominal)'],
                                    'readable' => true,
                                    'entity_headers' => ['country', 'territory'],
                                    // The page publishes one column per body, all of them
                                    // nominal GDP. Only the IMF column is read, so a
                                    // country has one figure rather than three vintages
                                    // of the same measure competing.
                                    'period' => 'header_year',
                                    'skip' => ['world bank', 'united nations', 'rank', 'per capita', '% of', 'share', 'change', 'region', 'date', 'notes', 'reference'],
                                    'columns' => [
                                        // The page publishes millions of US dollars.
                                        'imf' => ['indicator' => 'economy.gdp.nominal_bn', 'scale' => 0.001],
                                    ],
                                ],
                                [
                                    'key' => 'gdp_per_capita',
                                    'titles' => ['List of countries by GDP (nominal) per capita'],
                                    'readable' => true,
                                    'entity_headers' => ['country', 'territory'],
                                    'period' => 'header_year',
                                    'skip' => ['world bank', 'united nations', 'rank', '% of', 'share', 'change', 'region', 'date', 'notes', 'reference'],
                                    'columns' => [
                                        'imf' => ['indicator' => 'economy.gdp.per_capita.nominal', 'scale' => 1.0],
                                    ],
                                ],
                                [
                                    'key' => 'gdp_ppp',
                                    'titles' => ['List of countries by GDP (PPP)'],
                                    'readable' => true,
                                    'entity_headers' => ['country', 'territory'],
                                    // Same shape as the nominal page: a column per publishing
                                    // body, period in the header, values in millions. IMF
                                    // again, so PPP and nominal are read from one body.
                                    'period' => 'header_year',
                                    'skip' => ['world bank', 'cia', 'rank', 'per capita', '% of', 'share', 'change', 'region', 'date', 'notes', 'reference'],
                                    'columns' => [
                                        'imf' => ['indicator' => 'economy.gdp.ppp_bn', 'scale' => 0.001],
                                    ],
                                ],
                                [
                                    'key' => 'gdp_ppp_per_capita',
                                    'titles' => ['List of countries by GDP (PPP) per capita'],
                                    'readable' => true,
                                    'entity_headers' => ['country', 'territory'],
                                    'period' => 'header_year',
                                    'skip' => ['world bank', 'cia', 'rank', '% of', 'share', 'change', 'region', 'date', 'notes', 'reference'],
                                    'columns' => [
                                        'imf' => ['indicator' => 'economy.gdp.per_capita.ppp', 'scale' => 1.0],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'code' => 'wikipedia_debt_lists',
                        'name' => 'Wikipedia "List of countries by ..." — debt',
                        'description' => 'The external debt list page. The household, corporate and government debt pages are registered but deliberately not read: see each page\'s stated reason.',
                        'access_method' => 'wikitext',
                        'endpoint_url' => $config['adapters']['wikipedia']['api_url'],
                        'documentation_url' => 'https://www.mediawiki.org/wiki/API:Parsing_wikitext',
                        'auth_type' => 'none',
                        'update_frequency' => 'monthly',
                        'expected_latency_days' => 30,
                        'adapter_key' => 'wikipedia_list',
                        'config' => [
                            'pages' => [
                                [
                                    'key' => 'external_debt',
                                    'titles' => ['List of countries by external debt'],
                                    'readable' => true,
                                    'entity_headers' => ['country'],
                                    'period' => 'revision',
                                    'skip' => ['rank', 'per capita', '% of total', 'date', 'notes', 'reference'],
                                    'columns' => [
                                        'total debt (billions)' => ['indicator' => 'debt.external_bn_usd', 'scale' => 1.0],
                                        '% of gdp' => ['indicator' => 'debt.external_pct_gdp', 'scale' => 1.0],
                                    ],
                                ],
                                [
                                    'key' => 'household_debt',
                                    'titles' => ['List of countries by household debt'],
                                    'readable' => true,
                                    'entity_headers' => ['country'],
                                    // One column per year: `Country | 2023 | 2022 | ...`.
                                    // Only the most recent year is read, and the year comes
                                    // from the column header. The country cells are
                                    // `{{AFG}}`-style ISO3 templates.
                                    'period' => 'header_year',
                                    'skip' => ['2022', '2021', '2020', '2015', '2010', '2005', '2000', '1995', '1990', '1985', '1980', '1975', '1970', '1965', '1960', 'q1', 'q2', 'q3', 'q4', 'rank', 'date', 'notes', 'reference'],
                                    'columns' => [
                                        '2023' => ['indicator' => 'debt.household_pct_gdp', 'scale' => 1.0],
                                    ],
                                ],
                                [
                                    'key' => 'corporate_debt',
                                    'titles' => ['List of countries by corporate debt'],
                                    'readable' => true,
                                    'entity_headers' => ['country'],
                                    'period' => 'header_year',
                                    'skip' => ['2022', '2021', '2020', '2015', '2010', '2005', '2000', '1995', '1990', '1985', '1980', '1975', '1970', '1965', '1960', 'rank', 'date', 'notes', 'reference'],
                                    'columns' => [
                                        '2023' => ['indicator' => 'debt.corporate_pct_gdp', 'scale' => 1.0],
                                    ],
                                ],
                                [
                                    'key' => 'government_debt',
                                    'titles' => ['List of countries by government debt'],
                                    'readable' => true,
                                    'entity_headers' => ['country'],
                                    // The column header states the year: "General
                                    // government gross debt (2025)".
                                    'period' => 'header_year',
                                    'skip' => ['net debt', 'unfunded', 'rank', 'date', 'notes', 'reference'],
                                    'columns' => [
                                        'general government gross debt' => ['indicator' => 'debt.public_pct_gdp', 'scale' => 1.0],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'code' => 'wikipedia_wealth_lists',
                        'name' => 'Wikipedia "List of countries by ..." — wealth',
                        'description' => 'The total private wealth list page. The foreign exchange reserves and sovereign wealth fund pages are registered but deliberately not read: see each page\'s stated reason.',
                        'access_method' => 'wikitext',
                        'endpoint_url' => $config['adapters']['wikipedia']['api_url'],
                        'documentation_url' => 'https://www.mediawiki.org/wiki/API:Parsing_wikitext',
                        'auth_type' => 'none',
                        'update_frequency' => 'monthly',
                        'expected_latency_days' => 30,
                        'adapter_key' => 'wikipedia_list',
                        'config' => [
                            'pages' => [
                                [
                                    'key' => 'total_wealth',
                                    'titles' => ['List of countries by total private wealth'],
                                    'readable' => true,
                                    'entity_headers' => ['country'],
                                    'period' => 'revision',
                                    // The level and its ratio are read. Both cells are named
                                    // explicitly because the {{n+p}} before the ratio renders
                                    // two columns, so headings and cells drift apart there.
                                    'skip' => ['region', '% of', 'per capita', 'population', 'rank', 'date', 'notes', 'reference'],
                                    'columns' => [
                                        'total wealth (usd bn)' => ['indicator' => 'wealth.current_bn_usd', 'scale' => 1.0],
                                        // The heading sits at index 4 but the cell sits at 3,
                                        // because the {{n+p}} before it renders two columns.
                                        'wealth to gdp' => ['indicator' => 'wealth.to_gdp_ratio', 'scale' => 1.0, 'cell' => 3],
                                    ],
                                ],
                                [
                                    'key' => 'forex_reserves',
                                    'titles' => ['List of countries by foreign exchange reserves'],
                                    'readable' => true,
                                    'entity_headers' => ['country'],
                                    'period' => 'revision',
                                    // Country | Continent | including gold: US$ millions, change |
                                    // excluding gold: US$ millions, change | date | ref.
                                    // The group headers collapse, so cell 2 is named explicitly:
                                    // it is "Including gold" in millions, which is the figure
                                    // the reserves indicator is meant to hold.
                                    'skip' => ['continent', 'change', 'date', 'reference', 'ref'],
                                    'columns' => [
                                        'foreign exchange reserves' => ['indicator' => 'external.forex_reserves_bn_usd', 'scale' => 0.001, 'cell' => 2],
                                    ],
                                ],
                                [
                                    'key' => 'sovereign_funds',
                                    'titles' => ['List of sovereign wealth funds by country'],
                                    'readable' => true,
                                    'entity_headers' => ['country'],
                                    'period' => 'revision',
                                    // Country | Funds | Assets | Origin. The country cell is
                                    // `{{SIN}}{{efn|}}` — a bare ISO template plus an empty
                                    // note — which resolves now that bare templates are read.
                                    'skip' => ['funds', 'origin', 'rank', 'date', 'notes', 'reference'],
                                    'columns' => [
                                        'assets' => ['indicator' => 'wealth.sovereign_fund_bn_usd', 'scale' => 1.0],
                                    ],
                                ],
                                [
                                    'key' => 'fdi_inflows',
                                    'titles' => ['List of countries by foreign direct investment inflows'],
                                    'readable' => true,
                                    'entity_headers' => ['country'],
                                    // Rank | Country | 2020-2025 | 2025 | 2024 | 2023.
                                    // Only the latest single year is read: the cumulative
                                    // column would double-count.
                                    'period' => 'header_year',
                                    'skip' => ['rank', '2020-2025', '2024', '2023', '2022', '2021', '2020', 'date', 'notes', 'reference'],
                                    'columns' => [
                                        '2025' => ['indicator' => 'investment.fdi_inflow_bn_usd', 'scale' => 1.0],
                                    ],
                                ],
                                [
                                    'key' => 'niip',
                                    'titles' => ['Net international investment position'],
                                    // Countries and regions | NIIP (US$ millions) | GDP | ...
                                    // Values are explicitly signed (`+50,000` for a creditor,
                                    // `-11,468` for a debtor).
                                    'readable' => true,
                                    'entity_headers' => ['countries', 'country', 'region'],
                                    'period' => 'revision',
                                    'skip' => ['gdp', '% of', 'date', 'notes', 'reference'],
                                    'columns' => [
                                        'niip (us$ millions)' => ['indicator' => 'external.net_iip_bn_usd', 'scale' => 0.001],
                                    ],
                                ],
                                [
                                    'key' => 'financial_assets',
                                    'titles' => ['List of countries by financial assets'],
                                    // Country or region | 2025 | 2024 | 2023 | ... — one column
                                    // per year, in US$ billions, which is why the module keeps
                                    // a USD indicator beside the spreadsheet's euro one.
                                    'readable' => true,
                                    'entity_headers' => ['country', 'region'],
                                    'period' => 'header_year',
                                    'skip' => ['2024', '2023', '2022', '2021', '2020', '2017', '2016', '2015', '2014', '2013', '2012', '2011', '2010', 'rank', 'date', 'notes', 'reference'],
                                    'columns' => [
                                        '2025' => ['indicator' => 'wealth.financial_assets_bn_usd', 'scale' => 1.0],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'manual_curation',
                'publisher' => 'Project editor',
                'publisher_short' => 'Manual curation',
                'source_type' => 'manual',
                'homepage_url' => null,
                'terms_note' => 'A value a person has explicitly reviewed and corrected in the administration UI. Curated values are served ahead of every ingested source, but the value they replaced is still stored and still visible in the revision history.',
                'datasets' => [],
            ],
            [
                'code' => 'derived_calculations',
                'publisher' => 'Calculated by this module',
                'publisher_short' => 'Derived',
                'source_type' => 'other',
                'homepage_url' => null,
                'terms_note' => 'Values calculated from other observations using the demographic accounting identity — births, deaths, population change and net migration. Never replaces a figure a source actually published, and always flagged as derived.',
                'datasets' => [],
            ],
            [
                'code' => 'manual_csv_v1',
                'publisher' => 'Manually maintained project spreadsheet',
                'publisher_short' => 'Manual spreadsheet',
                'source_type' => 'manual',
                'homepage_url' => null,
                'terms_note' => 'Initial dataset handed over with the project. Unverified: no published methodology, mixed reference periods and units. Retained permanently so it can always be compared against and superseded by higher-ranked sources.',
                'datasets' => [
                    [
                        'code' => 'manual_demographic_csv',
                        'name' => 'Demographics(Demographic).csv',
                        'description' => 'Ten demographic tables that share one sheet: core country snapshot plus non-marital births, sex work estimates, divorce, single-parent households, births to foreign mothers, entertainment industry, pension exhaustion, childlessness and native share.',
                        'access_method' => 'manual_upload',
                        'endpoint_url' => null,
                        'documentation_url' => null,
                        'auth_type' => 'none',
                        'update_frequency' => 'adhoc',
                        'adapter_key' => 'manual_csv',
                    ],
                    [
                        'code' => 'manual_economy_csv',
                        'name' => 'Demographics(Economy).csv',
                        'description' => 'GDP, PPP, per-capita output, peak years, national wealth, informal economy and tourism.',
                        'access_method' => 'manual_upload',
                        'endpoint_url' => null,
                        'documentation_url' => null,
                        'auth_type' => 'none',
                        'update_frequency' => 'adhoc',
                        'adapter_key' => 'manual_csv',
                    ],
                    [
                        'code' => 'manual_debt_csv',
                        'name' => 'Demographics(Debt).csv',
                        'description' => 'External, public, household and corporate debt as shares of GDP, plus student loans.',
                        'access_method' => 'manual_upload',
                        'endpoint_url' => null,
                        'documentation_url' => null,
                        'auth_type' => 'none',
                        'update_frequency' => 'adhoc',
                        'adapter_key' => 'manual_csv',
                    ],
                    [
                        'code' => 'manual_indicator_csv',
                        'name' => 'Demographics(Indicator).csv',
                        'description' => 'Foreign exchange reserves, gross national savings, sovereign wealth funds, external creditor/debtor position, FDI and financial assets.',
                        'access_method' => 'manual_upload',
                        'endpoint_url' => null,
                        'documentation_url' => null,
                        'auth_type' => 'none',
                        'update_frequency' => 'adhoc',
                        'adapter_key' => 'manual_csv',
                    ],
                ],
            ],
        ];

        foreach ($sources as $definition) {
            $datasets = $definition['datasets'];
            unset($definition['datasets']);

            $source = Source::updateOrCreate(
                ['code' => $definition['code']],
                array_merge($definition, [
                    'credibility_rank' => $config['credibility_ranks'][$definition['source_type']] ?? 6,
                    'is_active' => true,
                ])
            );

            foreach ($datasets as $dataset) {
                SourceDataset::updateOrCreate(
                    ['code' => $dataset['code']],
                    array_merge($dataset, ['source_id' => $source->id, 'enabled' => true])
                );
            }
        }
    }
}
