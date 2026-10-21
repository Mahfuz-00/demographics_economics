<?php

/**
 * Shape of `Demographics(Debt).csv` — one table, one row per country.
 *
 * Every figure is a share of GDP (the file's "Total Debt (% of GDP)" column equals the
 * four sector columns summed, which confirms it). The trailing column is a global note
 * about development lending, repeated on every row, so it is skipped rather than
 * attached to whichever country happens to be on the line.
 */
return [
    'sub_tables' => [
        [
            'key' => 'debt_core',
            'first_label' => 'Country Name',
            'description' => 'External, public, household and corporate debt as shares of GDP.',
            'snapshot_year_columns' => [],
            'columns' => [
                ['label' => 'Country Name', 'role' => 'entity'],
                ['label' => 'External Debt', 'role' => 'value', 'indicator' => 'debt.external_pct_gdp'],
                ['label' => 'Public Debt', 'role' => 'value', 'indicator' => 'debt.public_pct_gdp'],
                ['label' => 'Household Debt', 'role' => 'value', 'indicator' => 'debt.household_pct_gdp'],
                ['label' => 'Corporate Debt', 'role' => 'value', 'indicator' => 'debt.corporate_pct_gdp'],
                ['label' => 'Total Debt (% of GDP)', 'role' => 'value', 'indicator' => 'debt.total_pct_gdp'],
                ['label' => 'Student Loan', 'role' => 'value', 'indicator' => 'debt.student_loan_pct_gdp'],
                ['label' => '', 'role' => 'skip', 'notes' => 'Spacer column.'],
                ['label' => '', 'role' => 'skip', 'notes' => 'A global note about development lending repeated on every row, not a per-country value.'],
            ],
        ],
    ],
];
