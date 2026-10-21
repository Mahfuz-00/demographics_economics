<?php

/**
 * Shape of `Demographics(Economy).csv` — one table, one row per country.
 *
 * Several columns are year-paired ("GDP" has its own "Year"), and the file repeats the
 * label `Year` five times, so year sources are referenced by column index rather than
 * by label. Columns 30-32 are a global reference list the sheet repeats on every row
 * (which countries are oil-dependent, tax havens, tourist-dependent); they are not
 * per-country values and are skipped rather than filed against the wrong country.
 */
return [
    'sub_tables' => [
        [
            'key' => 'economy_core',
            'first_label' => 'Country name',
            'description' => 'GDP, PPP, per-capita output, national wealth and tourism.',
            'snapshot_year_columns' => ['Year'],
            'columns' => [
                ['label' => 'Country name', 'role' => 'entity'],
                ['label' => 'GDP(Bn. $)', 'role' => 'value', 'indicator' => 'economy.gdp.nominal_bn', 'year_column_index' => 2],
                ['label' => 'Year', 'role' => 'year'],
                ['label' => 'Peak GDP', 'role' => 'value', 'indicator' => 'economy.gdp.peak_bn', 'year_column_index' => 4],
                ['label' => 'Peak Year', 'role' => 'year'],
                ['label' => 'Diff.', 'role' => 'value', 'indicator' => 'economy.gdp.peak_diff_bn'],
                ['label' => 'PC(2020)', 'role' => 'value', 'indicator' => 'economy.gdp.per_capita.nominal', 'transform' => 'fixed_year', 'transform_arg' => 2020],
                ['label' => 'Peak PC', 'role' => 'value', 'indicator' => 'economy.gdp.per_capita.peak', 'year_column_index' => 8],
                ['label' => 'Year', 'role' => 'year'],
                ['label' => 'Diff. PC', 'role' => 'value', 'indicator' => 'economy.gdp.per_capita.peak_diff'],
                ['label' => 'PPP GDP(Bn. $)', 'role' => 'value', 'indicator' => 'economy.gdp.ppp_bn', 'year_column_index' => 11],
                ['label' => 'Year', 'role' => 'year'],
                ['label' => 'Peak GDP', 'role' => 'value', 'indicator' => 'economy.gdp.ppp_peak_bn', 'year_column_index' => 13],
                ['label' => 'Peak Year', 'role' => 'year'],
                ['label' => 'Diff', 'role' => 'value', 'indicator' => 'economy.gdp.ppp_peak_diff_bn'],
                ['label' => 'PC(2020)', 'role' => 'value', 'indicator' => 'economy.gdp.per_capita.ppp', 'transform' => 'fixed_year', 'transform_arg' => 2020],
                ['label' => 'Peak PC', 'role' => 'value', 'indicator' => 'economy.gdp.per_capita.ppp_peak', 'year_column_index' => 17],
                ['label' => 'Year', 'role' => 'year'],
                ['label' => 'Diff. PC', 'role' => 'value', 'indicator' => 'economy.gdp.per_capita.ppp_peak_diff'],
                ['label' => 'PPP/Nom.(GDP)', 'role' => 'value', 'indicator' => 'economy.ppp_to_nominal.gdp_ratio'],
                ['label' => 'PPP/Nom.(PC)', 'role' => 'value', 'indicator' => 'economy.ppp_to_nominal.pc_ratio'],
                ['label' => 'Peak Wealth(bn. $)', 'role' => 'value', 'indicator' => 'wealth.peak_bn_usd', 'year_column_index' => 22],
                ['label' => 'Peak Year', 'role' => 'year'],
                ['label' => 'Current Wealth(bn. $)', 'role' => 'value', 'indicator' => 'wealth.current_bn_usd', 'year_column_index' => 24],
                ['label' => 'Year', 'role' => 'year'],
                ['label' => 'Diff.(bn. $)', 'role' => 'value', 'indicator' => 'wealth.decline_bn_usd'],
                ['label' => 'Wealth/GDP(Nom.)', 'role' => 'value', 'indicator' => 'wealth.to_gdp_ratio'],
                ['label' => 'Informal Economy(%)', 'role' => 'value', 'indicator' => 'economy.informal_economy_share', 'transform' => 'leading_number'],
                ['label' => '', 'role' => 'skip', 'notes' => 'Spacer column.'],
                ['label' => '', 'role' => 'skip', 'notes' => 'Spacer column.'],
                ['label' => 'Oil Dependent Countries(PC)', 'role' => 'skip', 'notes' => 'A global reference list repeated on every row, not a per-country value.'],
                ['label' => 'Tax Haven Countries(PC)', 'role' => 'skip', 'notes' => 'A global reference list repeated on every row, not a per-country value.'],
                ['label' => 'Tourist Dependent Countries', 'role' => 'skip', 'notes' => 'A global reference list repeated on every row, not a per-country value.'],
                ['label' => 'Int. Tourist (Mi.)', 'role' => 'value', 'indicator' => 'economy.tourists_inbound_millions'],
            ],
        ],
    ],
];
