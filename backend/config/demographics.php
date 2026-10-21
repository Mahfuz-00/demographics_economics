<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Source credibility
    |--------------------------------------------------------------------------
    | Lower rank wins when two sources report the same series point. National
    | statistical authorities outrank international organisations, which
    | outrank secondary compilations such as Wikipedia. The manual CSV that
    | seeded this module is deliberately lowest so live sources can supersede
    | it without deleting the original record.
    */
    'credibility_ranks' => [
        'national_statistics' => 1,
        'un' => 2,
        'world_bank' => 3,
        'imf' => 3,
        'oecd' => 3,
        'other_official' => 3,
        'wikipedia' => 4,
        'manual' => 5,
        'other' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Conflict detection
    |--------------------------------------------------------------------------
    | Applied when two sources disagree on the same (entity, indicator, period).
    | Percentage bands classify the disagreement; below `absolute_epsilon` no
    | conflict is recorded at all.
    */
    'conflicts' => [
        'absolute_epsilon' => 0.000001,
        'minor_below_percent' => 1.0,
        'notable_below_percent' => 5.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Raw payload snapshots
    |--------------------------------------------------------------------------
    | Every fetch is stored verbatim (gzipped) so any value can be traced back
    | to the exact bytes it was parsed from.
    */
    'snapshots' => [
        'disk' => env('DEMOGRAPHICS_SNAPSHOT_DISK', 'local'),
        'path' => 'ingestion',
        'compress' => true,
    ],

    'ingestion' => [
        'queue' => env('DEMOGRAPHICS_QUEUE', 'default'),
        'chunk_size' => 500,
        'http' => [
            'timeout' => 60,
            'connect_timeout' => 15,
            'retries' => 3,
            'retry_delay_ms' => 1500,
            'ca_bundle' => env('DEMOGRAPHICS_CA_BUNDLE'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source adapters
    |--------------------------------------------------------------------------
    */
    'adapters' => [
        'world_bank' => [
            'base_url' => env('WORLD_BANK_API_URL', 'https://api.worldbank.org/v2'),
            'per_page' => 20000,
            'start_year' => (int) env('WORLD_BANK_START_YEAR', 1960),
            'end_year' => (int) env('WORLD_BANK_END_YEAR', 2100),
        ],
        'un_wpp' => [
            'portal_url' => 'https://population.un.org/dataportalapi/api/v1',
            'bulk_url' => 'https://population.un.org/wpp/assets/Excel Files/1_Indicator (Standard)/CSV_FILES',
            'api_key_env' => 'UN_DATAPORTAL_API_KEY',
            'last_estimate_year' => 2023,
        ],
        'wikipedia' => [
            'api_url' => env('WIKIPEDIA_API_URL', 'https://en.wikipedia.org/w/api.php'),
            'user_agent' => env('WIKIPEDIA_USER_AGENT', 'DemographicsEconomy/1.0'),
            'min_interval_ms' => 400,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source discovery
    |--------------------------------------------------------------------------
    | When a scrape finds nothing usable for a country, a web search runs and the
    | results are stored as candidates. Candidates are proposals only: nothing is
    | recorded as data until a person accepts one.
    */
    'discovery' => [
        'search_provider' => env('DEMOGRAPHICS_SEARCH_PROVIDER', 'duckduckgo'),
        'user_agent' => env('WIKIPEDIA_USER_AGENT', 'DemographicsEconomy/1.0'),
        'results_per_query' => 6,
        'query_templates' => [
            '{country} vital statistics births deaths per year',
            '{country} national statistics office population births deaths',
            '{country} historical population birth rate death rate table',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Manual CSV import
    |--------------------------------------------------------------------------
    */
    'manual_csv' => [
        'source_code' => 'manual_csv_v1',
        'batch_kind' => 'csv_manual',
        'default_missing_reason' => 'not_published',
    ],

];
