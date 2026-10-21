<?php

/**
 * Shape of `Demographics(Indicator).csv` — one table, one row per country.
 *
 * Reserves, saving, sovereign funds, creditor/debtor position, direct investment and
 * financial assets. There is no year column anywhere in the file, so every value is
 * dated to the document's compilation year and flagged as an assumed period.
 */
return [
    'sub_tables' => [
        [
            'key' => 'wealth_core',
            'first_label' => 'Country',
            'description' => 'Reserves, saving, sovereign wealth funds, external position and FDI.',
            'snapshot_year_columns' => [],
            'columns' => [
                ['label' => 'Country', 'role' => 'entity'],
                ['label' => 'Forex Reserve(Bn $)', 'role' => 'value', 'indicator' => 'external.forex_reserves_bn_usd'],
                ['label' => 'Gross national savings(% of GDP)', 'role' => 'value', 'indicator' => 'economy.gross_savings_pct_gdp'],
                ['label' => 'Sovereign wealth funds(Bn $)', 'role' => 'value', 'indicator' => 'wealth.sovereign_fund_bn_usd'],
                ['label' => 'Creditor(Bn $)', 'role' => 'value', 'indicator' => 'external.creditor_bn_usd'],
                ['label' => 'Debtor(Bn $)', 'role' => 'value', 'indicator' => 'external.debtor_bn_usd'],
                ['label' => 'Net NIIP(Bn $)', 'role' => 'value', 'indicator' => 'external.net_iip_bn_usd'],
                ['label' => 'FDI Out(Bn $)', 'role' => 'value', 'indicator' => 'investment.fdi_outflow_bn_usd'],
                ['label' => 'FDI In(Bn $)', 'role' => 'value', 'indicator' => 'investment.fdi_inflow_bn_usd'],
                ['label' => 'Net FDI(Bn $)', 'role' => 'value', 'indicator' => 'investment.fdi_net_bn_usd'],
                ['label' => 'Financial assets(Bn EUR)', 'role' => 'value', 'indicator' => 'wealth.financial_assets_bn_eur'],
            ],
        ],
    ],
];
