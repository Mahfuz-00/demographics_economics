<?php

/**
 * The exact shape of `Demographics(Demographic).csv`.
 *
 * The file is a spreadsheet sheet, not a single table: several independent tables
 * sit side by side, each with its own key column. Column positions below are
 * relative to the start of their sub-table, and sub-table order is left-to-right
 * as the header appears — which is how the splitter distinguishes the repeated
 * `Country` header cells.
 *
 * Roles
 *   entity      key column for the sub-table; resolved to an entity (usually a country)
 *   value       numeric value for `indicator`
 *   value_year  numeric value with an embedded reference year ("0.93/2025")
 *   year        a year that dates a paired value column; not stored on its own
 *   label       descriptive text carried on the row (e.g. continent)
 *   note        free-text annotation kept as provenance
 *   skip        empty spacer column
 *
 * `year_column` names the column (within the same sub-table) that supplies the
 * period for a value. When absent, the row's snapshot year is used.
 */
return [
    'sub_tables' => [
        [
            'key' => 'core',
            'first_label' => 'Country',
            'description' => 'Main per-country demographic snapshot.',
            'snapshot_year_columns' => ['By year', 'By Year (Immigrants)', 'Peak Year'],
            'columns' => [
                ['label' => 'Country', 'role' => 'entity'],
                ['label' => 'Continent', 'role' => 'label'],
                ['label' => 'Peak Population', 'role' => 'value', 'indicator' => 'pop.peak', 'year_column' => 'Peak Year'],
                ['label' => 'Peak Year', 'role' => 'year'],
                ['label' => 'Present Population', 'role' => 'value', 'indicator' => 'pop.total'],
                ['label' => 'Population Drop', 'role' => 'value', 'indicator' => 'pop.decline.absolute'],
                ['label' => '% Drop', 'role' => 'value', 'indicator' => 'pop.decline.percent'],
                ['label' => 'Current Trend', 'role' => 'value', 'indicator' => 'pop.trend.annual', 'year_column' => 'By year'],
                ['label' => '# of year (-) growth', 'role' => 'value', 'indicator' => 'pop.years.declining'],
                ['label' => 'By year', 'role' => 'year'],
                ['label' => 'Total Nature Decline(Since 1970s)', 'role' => 'value', 'indicator' => 'pop.natural_decline.since_1970s', 'transform' => 'cumulative_note'],
                ['label' => 'Decline year(est.)', 'role' => 'value', 'indicator' => 'pop.natural_decline.start_year'],
                ['label' => 'Immigrants', 'role' => 'value', 'indicator' => 'mig.immigrant_stock', 'year_column' => 'By Year (Immigrants)'],
                ['label' => 'By Year (Immigrants)', 'role' => 'year'],
                ['label' => '% of Total Pop.', 'role' => 'value', 'indicator' => 'mig.foreign_born_share', 'year_column' => 'By Year (Immigrants)'],
                ['label' => 'Native People(%)', 'role' => 'value', 'indicator' => 'pop.native_share'],
                ['label' => 'Emigration', 'role' => 'value', 'indicator' => 'mig.emigrant_stock'],
                ['label' => 'Net Immigrant', 'role' => 'value', 'indicator' => 'mig.net_stock'],
                ['label' => 'Diaspora', 'role' => 'value', 'indicator' => 'pop.diaspora'],
                ['label' => 'Fertility Rate', 'role' => 'value', 'indicator' => 'fert.tfr'],
                ['label' => 'Lowest F.Rate', 'role' => 'value_year', 'indicator' => 'fert.tfr.minimum'],
                ['label' => 'YWFRDBRL', 'role' => 'value', 'indicator' => 'fert.tfr.sub_replacement_start_year'],
                ['label' => 'Birth Rate', 'role' => 'value', 'indicator' => 'birth.rate.crude'],
                ['label' => 'Death Rate', 'role' => 'value', 'indicator' => 'death.rate.crude'],
                ['label' => 'Change', 'role' => 'value', 'indicator' => 'pop.growth_rate'],
                ['label' => 'Infant Mortality Rate (Per 1000)', 'role' => 'value', 'indicator' => 'death.infant_rate'],
                ['label' => '65+(%)', 'role' => 'value', 'indicator' => 'pop.age_65plus_share'],
                ['label' => 'Median Age', 'role' => 'value', 'indicator' => 'pop.median_age'],
                ['label' => 'Life Expectancy', 'role' => 'value', 'indicator' => 'death.life_expectancy'],
                ['label' => 'Per Capita(N)', 'role' => 'value', 'indicator' => 'economy.gdp.per_capita.nominal'],
                ['label' => '', 'role' => 'skip'],
                ['label' => '', 'role' => 'skip'],
                ['label' => 'Color Represention', 'role' => 'note'],
            ],
        ],
        [
            'key' => 'nonmarital_birth',
            'first_label' => 'Country',
            'description' => 'Share of live births outside marriage.',
            'columns' => [
                ['label' => 'Country', 'role' => 'entity'],
                ['label' => '% of Nonmarital Birth', 'role' => 'value', 'indicator' => 'birth.nonmarital_share'],
                ['label' => 'Year', 'role' => 'year'],
            ],
        ],
        [
            'key' => 'sex_workers',
            'first_label' => 'Country',
            'description' => 'Reported estimate of people in sex work. Sensitive; not authoritative.',
            'columns' => [
                ['label' => 'Country', 'role' => 'entity'],
                ['label' => 'Prostitutes', 'role' => 'value', 'indicator' => 'pop.sex_workers.reported'],
                ['label' => 'Year', 'role' => 'year'],
            ],
        ],
        [
            'key' => 'divorce',
            'first_label' => 'Country',
            'description' => 'Divorce rate, unit as reported in the source.',
            'columns' => [
                ['label' => 'Country', 'role' => 'entity'],
                ['label' => 'Divorce Rate(%)', 'role' => 'value', 'indicator' => 'household.divorce_rate'],
                ['label' => 'Year', 'role' => 'year'],
            ],
        ],
        [
            'key' => 'single_parent',
            'first_label' => 'Country',
            'description' => 'Share of households headed by a single parent.',
            'columns' => [
                ['label' => 'Country', 'role' => 'entity'],
                ['label' => 'Single Parent Household(%)', 'role' => 'value', 'indicator' => 'household.single_parent_share'],
                ['label' => 'Year', 'role' => 'year'],
            ],
        ],
        [
            'key' => 'foreign_mother_births',
            'first_label' => 'Country[2019]',
            'description' => 'Share of live births to foreign mothers, reference year 2019.',
            'columns' => [
                ['label' => 'Country[2019]', 'role' => 'entity'],
                ['label' => 'Live Birth From Foreign Mother(%)', 'role' => 'value', 'indicator' => 'birth.foreign_mother_share', 'transform' => 'fixed_year', 'transform_arg' => 2019],
            ],
        ],
        [
            'key' => 'entertainment',
            'first_label' => 'Entertaintment Industry Value',
            'description' => 'Industry-category values, keyed by industry rather than country.',
            'columns' => [
                ['label' => 'Entertaintment Industry Value', 'role' => 'entity'],
                ['label' => 'Bn. $', 'role' => 'value', 'indicator' => 'economy.entertainment_value'],
                ['label' => 'Year', 'role' => 'year'],
            ],
        ],
        [
            'key' => 'pension',
            'first_label' => 'Country',
            'description' => 'Year the national pension fund is projected to be exhausted.',
            'columns' => [
                ['label' => 'Country', 'role' => 'entity'],
                ['label' => 'Year Pension Money will run out', 'role' => 'value', 'indicator' => 'social.pension_exhaustion_year'],
            ],
        ],
        [
            'key' => 'childlessness',
            'first_label' => 'Childlessness',
            'description' => 'Share of women (or population) reported as childless.',
            'columns' => [
                ['label' => 'Childlessness', 'role' => 'entity'],
                ['label' => '%', 'role' => 'value', 'indicator' => 'fert.childlessness'],
            ],
        ],
        [
            'key' => 'native_share',
            'first_label' => 'Country',
            'description' => 'Native / ethnic-majority share of the population.',
            'columns' => [
                ['label' => 'Country', 'role' => 'entity'],
                ['label' => 'Native', 'role' => 'value', 'indicator' => 'pop.native_share'],
                ['label' => 'Year', 'role' => 'year'],
            ],
        ],
    ],
];
