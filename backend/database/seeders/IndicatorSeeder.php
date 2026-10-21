<?php

namespace Database\Seeders;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use Illuminate\Database\Seeder;

class IndicatorSeeder extends Seeder
{
    public function run(): void
    {
        // [name, description, sort order, module]
        $categories = [
            'population' => ['Population', 'Headcounts, structure and change.', 10, 'demographics'],
            'fertility' => ['Fertility & Births', 'Childbearing and birth outcomes.', 20, 'demographics'],
            'mortality' => ['Mortality & Health', 'Deaths and survival.', 30, 'demographics'],
            'migration' => ['Migration', 'Movement of people across borders.', 40, 'demographics'],
            'household' => ['Households & Family', 'Family formation and dissolution.', 50, 'demographics'],
            'social' => ['Society & Public Finance', 'Social and fiscal context.', 60, 'demographics'],
            'economy' => ['Economy', 'Economic context carried by the source data.', 70, 'demographics'],
            'national_accounts' => ['National accounts', 'Output, prices and saving.', 80, 'economy'],
            'public_finance' => ['Public finance', 'Government and external debt.', 90, 'debt'],
            'private_debt' => ['Household & corporate debt', 'Private-sector leverage.', 100, 'debt'],
            'national_wealth' => ['National wealth', 'Accumulated wealth and its funds.', 110, 'wealth'],
            'external_position' => ['External position & investment', 'Reserves, creditor position and direct investment.', 120, 'wealth'],
        ];

        $categoryIds = [];
        foreach ($categories as $code => [$name, $description, $order, $module]) {
            $category = IndicatorCategory::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'description' => $description, 'module' => $module, 'sort_order' => $order]
            );
            $categoryIds[$code] = $category->id;
        }

        $indicators = [
            // Population
            ['pop.total', 'population', 'Present population', 'Total population at the reference date.', 'persons', 'level', 0, ['typical_min_year' => 1950, 'typical_max_year' => 2100]],
            ['pop.peak', 'population', 'Peak population', 'Highest population recorded, dated to the year it occurred.', 'persons', 'level', 0],
            ['pop.change.annual', 'population', 'Annual change in population', 'Year-on-year change in total population, as published in vital-statistics tables.', 'persons', 'count', 0],
            ['pop.natural_change.rate', 'population', 'Rate of natural change', 'Births less deaths per 1,000 population in a year.', 'per_1000', 'rate', 1],
            ['pop.natural_change.absolute', 'population', 'Natural change', 'Births less deaths in a year, as an absolute number.', 'persons', 'count', 0],
            ['pop.decline.absolute', 'population', 'Decline from peak', 'Absolute fall from the peak population to the latest figure.', 'persons', 'level', 0],
            ['pop.decline.percent', 'population', 'Decline from peak (%)', 'Percentage fall from the peak population to the latest figure.', 'percent', 'rate', 2],
            ['pop.trend.annual', 'population', 'Current annual change', 'Most recent annual change in population as reported by the source.', 'persons', 'level', 0],
            ['pop.years.declining', 'population', 'Years of population decline', 'Number of consecutive years in which the population has fallen.', 'count', 'count', 0],
            ['pop.natural_decline.since_1970s', 'population', 'Cumulative natural decline since the 1970s', 'Cumulative excess of deaths over births recorded by the source.', 'persons', 'count', 0],
            ['pop.natural_decline.start_year', 'population', 'Year natural decline began', 'Year in which deaths began to exceed births.', 'year', 'year', 0],
            ['pop.native_share', 'population', 'Native / ethnic-majority share', 'Share of the population of native or ethnic-majority origin.', 'percent', 'rate', 2],
            ['pop.diaspora', 'population', 'Diaspora population', 'People of the country of origin living abroad.', 'persons', 'level', 0, ['is_stock' => true]],
            ['pop.median_age', 'population', 'Median age', 'Age that divides the population in half.', 'years', 'level', 1],
            ['pop.age_65plus_share', 'population', 'Population aged 65+', 'Share of the population aged 65 and over.', 'percent', 'rate', 2],
            ['pop.growth_rate', 'population', 'Population change', 'Annual rate of population change as reported by the source.', 'percent', 'rate', 2],
            ['pop.sex_workers.reported', 'population', 'Reported people in sex work', 'Estimate recorded by the source. Unverified, often dated and definitionally inconsistent.', 'persons', 'count', 0, ['is_sensitive' => true]],

            // Fertility & births
            ['fert.tfr', 'fertility', 'Total fertility rate', 'Average children a woman would bear over her lifetime at current age-specific rates.', 'children_per_woman', 'rate', 2],
            ['fert.tfr.minimum', 'fertility', 'Lowest recorded total fertility rate', 'Lowest total fertility rate recorded, dated to the year it occurred.', 'children_per_woman', 'rate', 3],
            ['fert.tfr.sub_replacement_start_year', 'fertility', 'Year fertility fell below replacement', 'Year the total fertility rate first fell below the replacement level of 2.1.', 'year', 'year', 0],
            ['fert.childlessness', 'fertility', 'Childlessness', 'Share of women (or of the cohort) who remain childless.', 'percent', 'rate', 1],
            ['birth.rate.crude', 'fertility', 'Crude birth rate', 'Live births per 1,000 population in a year.', 'per_1000', 'rate', 1],
            ['birth.count.annual', 'fertility', 'Live births per year', 'Number of live births in a year, as published in vital-statistics tables.', 'persons', 'count', 0],
            ['birth.nonmarital_share', 'fertility', 'Births outside marriage', 'Share of live births to unmarried parents.', 'percent', 'rate', 1],
            ['birth.foreign_mother_share', 'fertility', 'Live births to foreign mothers', 'Share of live births to mothers who are foreign nationals or citizens.', 'percent', 'rate', 1],

            // Mortality
            ['death.rate.crude', 'mortality', 'Crude death rate', 'Deaths per 1,000 population in a year.', 'per_1000', 'rate', 1],
            ['death.count.annual', 'mortality', 'Deaths per year', 'Number of deaths in a year, as published in vital-statistics tables.', 'persons', 'count', 0],
            ['death.infant_rate', 'mortality', 'Infant mortality rate', 'Deaths of children under one year per 1,000 live births.', 'per_1000', 'rate', 1],
            ['death.life_expectancy', 'mortality', 'Life expectancy at birth', 'Average number of years a newborn would live at current mortality rates.', 'years', 'level', 1],

            // Migration
            ['mig.immigrant_stock', 'migration', 'Immigrant stock', 'People born abroad, or foreign nationals, resident in the country.', 'persons', 'level', 0, ['is_stock' => true]],
            ['mig.foreign_born_share', 'migration', 'Immigrant share of population', 'Immigrant stock as a share of the total population.', 'percent', 'rate', 2],
            ['mig.emigrant_stock', 'migration', 'Emigrant stock', 'People born in the country who live abroad.', 'persons', 'level', 0, ['is_stock' => true]],
            ['mig.net_stock', 'migration', 'Net migrant stock', 'Immigrant stock less emigrant stock.', 'persons', 'level', 0, ['is_stock' => true]],
            ['mig.net_annual', 'migration', 'Net migration', 'Net number of migrants in a year (arrivals less departures).', 'persons', 'count', 0],
            ['mig.rate.crude', 'migration', 'Crude migration rate', 'Net migration per 1,000 population in a year.', 'per_1000', 'rate', 1],

            // Households
            ['household.divorce_rate', 'household', 'Divorce rate', 'Divorce rate. The source labels the column as a percentage but does not define the denominator; treat comparisons with care.', 'percent', 'rate', 1],
            ['household.single_parent_share', 'household', 'Single-parent households', 'Share of households headed by a single parent.', 'percent', 'rate', 1],

            // Society & public finance
            ['social.pension_exhaustion_year', 'social', 'Projected pension fund exhaustion year', 'Year the national pension fund is projected to run out, as stated by the source.', 'year', 'year', 0],

            // Year-on-year change in the vital statistics, calculated from the counts.
            ['pop.change.natural_share', 'population', 'Net change from births and deaths', 'Natural change as a share of total population change. The remainder is net migration.', 'percent', 'rate', 1, ['is_derived' => true]],
            ['pop.natural_change.change_pct', 'population', 'Change in natural change', 'Year-on-year percentage change in births less deaths.', 'percent', 'rate', 1, ['is_derived' => true]],
            ['birth.change.pct', 'fertility', 'Change in live births', 'Year-on-year percentage change in the number of live births.', 'percent', 'rate', 1, ['is_derived' => true]],
            ['death.change.pct', 'mortality', 'Change in deaths', 'Year-on-year percentage change in the number of deaths.', 'percent', 'rate', 1, ['is_derived' => true]],

            // Economy
            ['economy.gdp.per_capita.nominal', 'economy', 'GDP per capita (nominal)', 'Nominal gross domestic product per person.', 'currency_usd', 'level', 0, ['is_per_capita' => true, 'module' => 'economy']],
            ['economy.entertainment_value', 'economy', 'Entertainment industry value', 'Reported value of the entertainment industry, keyed by industry rather than country.', 'currency_usd_bn', 'currency', 2, ['module' => 'economy']],

            // Economy — national accounts (from Demographics(Economy).csv)
            ['economy.gdp.nominal_bn', 'national_accounts', 'GDP (nominal)', 'Gross domestic product at current prices, in billions of US dollars.', 'currency_usd_bn', 'currency', 0, ['module' => 'economy']],
            ['economy.gdp.peak_bn', 'national_accounts', 'Peak GDP (nominal)', 'Highest nominal GDP recorded, dated to the year it occurred.', 'currency_usd_bn', 'currency', 0, ['module' => 'economy']],
            ['economy.gdp.peak_diff_bn', 'national_accounts', 'GDP below its peak', 'Difference between the latest nominal GDP and the peak.', 'currency_usd_bn', 'currency', 0, ['module' => 'economy']],
            ['economy.gdp.per_capita.peak', 'national_accounts', 'Peak GDP per capita (nominal)', 'Highest nominal GDP per person, dated to the year it occurred.', 'currency_usd', 'level', 0, ['is_per_capita' => true, 'module' => 'economy']],
            ['economy.gdp.per_capita.peak_diff', 'national_accounts', 'GDP per capita below its peak', 'Difference between the latest and the peak nominal GDP per person.', 'currency_usd', 'level', 0, ['is_per_capita' => true, 'module' => 'economy']],
            ['economy.gdp.ppp_bn', 'national_accounts', 'GDP (PPP)', 'Gross domestic product at purchasing power parity, in billions of US dollars.', 'currency_usd_bn', 'currency', 0, ['module' => 'economy']],
            ['economy.gdp.ppp_peak_bn', 'national_accounts', 'Peak GDP (PPP)', 'Highest GDP at purchasing power parity, dated to the year it occurred.', 'currency_usd_bn', 'currency', 0, ['module' => 'economy']],
            ['economy.gdp.ppp_peak_diff_bn', 'national_accounts', 'GDP (PPP) below its peak', 'Difference between the latest and the peak GDP at purchasing power parity.', 'currency_usd_bn', 'currency', 0, ['module' => 'economy']],
            ['economy.gdp.per_capita.ppp', 'national_accounts', 'GDP per capita (PPP)', 'GDP at purchasing power parity per person.', 'currency_usd', 'level', 0, ['is_per_capita' => true, 'module' => 'economy']],
            ['economy.gdp.per_capita.ppp_peak', 'national_accounts', 'Peak GDP per capita (PPP)', 'Highest GDP per person at purchasing power parity.', 'currency_usd', 'level', 0, ['is_per_capita' => true, 'module' => 'economy']],
            ['economy.gdp.per_capita.ppp_peak_diff', 'national_accounts', 'GDP per capita (PPP) below its peak', 'Difference between the latest and the peak GDP per person at purchasing power parity.', 'currency_usd', 'level', 0, ['is_per_capita' => true, 'module' => 'economy']],
            ['economy.ppp_to_nominal.gdp_ratio', 'national_accounts', 'PPP to nominal GDP ratio', 'GDP at purchasing power parity divided by nominal GDP.', 'ratio', 'ratio', 2, ['module' => 'economy']],
            ['economy.ppp_to_nominal.pc_ratio', 'national_accounts', 'PPP to nominal per-capita ratio', 'GDP per person at purchasing power parity divided by nominal GDP per person.', 'ratio', 'ratio', 2, ['module' => 'economy']],
            ['economy.informal_economy_share', 'national_accounts', 'Informal economy', 'Share of output produced outside formal arrangements.', 'percent', 'rate', 1, ['module' => 'economy']],
            ['economy.gross_savings_pct_gdp', 'national_accounts', 'Gross national savings', 'Gross national savings as a share of GDP.', 'percent', 'rate', 1, ['module' => 'economy']],
            ['economy.tourists_inbound_millions', 'national_accounts', 'International tourist arrivals', 'Inbound international tourist arrivals, in millions.', 'count', 'count', 1, ['module' => 'economy']],

            // Debt (from Demographics(Debt).csv) — shares of GDP
            ['debt.external_bn_usd', 'public_finance', 'External debt (absolute)', 'Gross external debt in billions of US dollars, as the list pages publish it before converting to a share of GDP.', 'currency_usd_bn', 'currency', 1, ['module' => 'debt']],
            ['debt.external_pct_gdp', 'public_finance', 'External debt', 'Gross external debt as a share of GDP.', 'percent', 'rate', 2, ['module' => 'debt']],
            ['debt.public_pct_gdp', 'public_finance', 'Public debt', 'General government debt as a share of GDP.', 'percent', 'rate', 3, ['module' => 'debt']],
            ['debt.student_loan_pct_gdp', 'public_finance', 'Student loans', 'Outstanding student loan balances as a share of GDP.', 'percent', 'rate', 2, ['module' => 'debt']],
            ['debt.household_pct_gdp', 'private_debt', 'Household debt', 'Household debt as a share of GDP.', 'percent', 'rate', 2, ['module' => 'debt']],
            ['debt.corporate_pct_gdp', 'private_debt', 'Corporate debt', 'Non-financial corporate debt as a share of GDP.', 'percent', 'rate', 2, ['module' => 'debt']],
            ['debt.total_pct_gdp', 'private_debt', 'Total debt', 'All debt sectors combined as a share of GDP.', 'percent', 'rate', 2, ['module' => 'debt']],

            // Wealth (from Demographics(Economy).csv and Demographics(Indicator).csv)
            ['wealth.peak_bn_usd', 'national_wealth', 'Peak national wealth', 'Highest recorded national wealth, dated to the year it occurred.', 'currency_usd_bn', 'currency', 0, ['module' => 'wealth']],
            ['wealth.current_bn_usd', 'national_wealth', 'National wealth', 'Latest recorded national wealth.', 'currency_usd_bn', 'currency', 0, ['module' => 'wealth']],
            ['wealth.decline_bn_usd', 'national_wealth', 'Wealth below its peak', 'Difference between the latest and the peak national wealth.', 'currency_usd_bn', 'currency', 0, ['module' => 'wealth']],
            ['wealth.to_gdp_ratio', 'national_wealth', 'Wealth to GDP ratio', 'National wealth divided by nominal GDP.', 'ratio', 'ratio', 2, ['module' => 'wealth']],
            ['wealth.sovereign_fund_bn_usd', 'national_wealth', 'Sovereign wealth fund assets', 'Assets held by sovereign wealth funds, in billions of US dollars.', 'currency_usd_bn', 'currency', 1, ['module' => 'wealth']],
            ['wealth.financial_assets_bn_eur', 'national_wealth', 'Financial assets', 'Financial assets held, in billions of euros.', 'currency_eur_bn', 'currency', 1, ['module' => 'wealth']],
            ['wealth.financial_assets_bn_usd', 'national_wealth', 'Financial assets (USD)', 'Financial assets held, in billions of US dollars — the unit the list page publishes, alongside the euro figure from the spreadsheet.', 'currency_usd_bn', 'currency', 1, ['module' => 'wealth']],
            ['external.forex_reserves_bn_usd', 'external_position', 'Foreign exchange reserves', 'Official reserve assets, in billions of US dollars.', 'currency_usd_bn', 'currency', 1, ['module' => 'wealth']],
            ['external.creditor_bn_usd', 'external_position', 'Net creditor position', 'Net external creditor position, in billions of US dollars.', 'currency_usd_bn', 'currency', 1, ['module' => 'wealth']],
            ['external.debtor_bn_usd', 'external_position', 'Net debtor position', 'Net external debtor position, in billions of US dollars.', 'currency_usd_bn', 'currency', 1, ['module' => 'wealth']],
            ['external.net_iip_bn_usd', 'external_position', 'Net international investment position', 'External assets less external liabilities, in billions of US dollars.', 'currency_usd_bn', 'currency', 1, ['module' => 'wealth']],
            ['investment.fdi_outflow_bn_usd', 'external_position', 'Foreign direct investment, outward', 'Outward direct investment stock, in billions of US dollars.', 'currency_usd_bn', 'currency', 1, ['module' => 'wealth']],
            ['investment.fdi_inflow_bn_usd', 'external_position', 'Foreign direct investment, inward', 'Inward direct investment stock, in billions of US dollars.', 'currency_usd_bn', 'currency', 1, ['module' => 'wealth']],
            ['investment.fdi_net_bn_usd', 'external_position', 'Foreign direct investment, net', 'Inward less outward direct investment, in billions of US dollars.', 'currency_usd_bn', 'currency', 1, ['module' => 'wealth']],
        ];

        /*
         * Column order for the country table, mirroring the source spreadsheet.
         * Values are the CSV column index times ten, so this block reads in the same
         * order as `Demographics(Demographic).csv` and the mapping stays obvious.
         * Indicators that are not in that sheet follow from 300.
         */
        $displayOrder = [
            'pop.peak' => 20,                              // Peak Population
            'pop.total' => 40,                             // Present Population
            'pop.decline.absolute' => 50,                  // Population Drop
            'pop.decline.percent' => 60,                   // % Drop
            'pop.trend.annual' => 70,                      // Current Trend
            'pop.years.declining' => 80,                   // # of year (-) growth
            'pop.natural_decline.since_1970s' => 100,      // Total Nature Decline
            'pop.natural_decline.start_year' => 110,       // Decline year (est.)
            'mig.immigrant_stock' => 120,                  // Immigrants
            'mig.foreign_born_share' => 140,               // % of Total Pop.
            'pop.native_share' => 150,                     // Native People (%)
            'mig.emigrant_stock' => 160,                   // Emigration
            'mig.net_stock' => 170,                        // Net Immigrant
            'pop.diaspora' => 180,                         // Diaspora
            'fert.tfr' => 190,                             // Fertility Rate
            'fert.tfr.minimum' => 200,                     // Lowest F.Rate
            'fert.tfr.sub_replacement_start_year' => 210,  // YWFRDBRL
            'birth.rate.crude' => 220,                     // Birth Rate
            'death.rate.crude' => 230,                     // Death Rate
            'pop.growth_rate' => 240,                      // Change
            'death.infant_rate' => 250,                    // Infant Mortality Rate
            'pop.age_65plus_share' => 260,                 // 65+ (%)
            'pop.median_age' => 270,                       // Median Age
            'death.life_expectancy' => 280,                // Life Expectancy

            // Not columns in that sheet: vital statistics and the right-hand tables.
            'pop.change.annual' => 300,
            'pop.natural_change.absolute' => 310,
            'pop.change.natural_share' => 315,
            'pop.natural_change.rate' => 320,
            'pop.natural_change.change_pct' => 325,
            'birth.count.annual' => 330,
            'birth.change.pct' => 335,
            'death.count.annual' => 340,
            'death.change.pct' => 345,
            'mig.rate.crude' => 350,
            'mig.net_annual' => 355,
            'birth.nonmarital_share' => 360,
            'birth.foreign_mother_share' => 370,
            'fert.childlessness' => 380,
            'household.divorce_rate' => 390,
            'household.single_parent_share' => 400,
            'pop.sex_workers.reported' => 410,
            'social.pension_exhaustion_year' => 420,
            // Last by request: it is keyed by industry rather than country, so it does
            // not belong in the run of country columns.
            'economy.entertainment_value' => 9999,

            // Economy / Debt / Wealth modules, in the order their spreadsheets list them.
            'economy.gdp.nominal_bn' => 500,
            'economy.gdp.peak_bn' => 510,
            'economy.gdp.peak_diff_bn' => 530,
            // Per capita follows the total it divides, carrying its own peak with it —
            // the same order the source spreadsheet uses: total, peak, decline, per head.
            'economy.gdp.per_capita.nominal' => 540,
            'economy.gdp.per_capita.peak' => 550,
            'economy.gdp.per_capita.peak_diff' => 560,
            'economy.gdp.ppp_bn' => 580,
            'economy.gdp.ppp_peak_bn' => 600,
            'economy.gdp.ppp_peak_diff_bn' => 620,
            'economy.gdp.per_capita.ppp' => 630,
            'economy.gdp.per_capita.ppp_peak' => 640,
            'economy.gdp.per_capita.ppp_peak_diff' => 660,
            'economy.ppp_to_nominal.gdp_ratio' => 670,
            'economy.ppp_to_nominal.pc_ratio' => 680,
            'wealth.current_bn_usd' => 700,
            'wealth.peak_bn_usd' => 710,
            'wealth.decline_bn_usd' => 730,
            'wealth.to_gdp_ratio' => 740,
            'economy.informal_economy_share' => 750,
            'economy.tourists_inbound_millions' => 800,

            'debt.external_bn_usd' => 895,
            'debt.external_pct_gdp' => 900,
            'debt.public_pct_gdp' => 910,
            'debt.household_pct_gdp' => 920,
            'debt.corporate_pct_gdp' => 930,
            'debt.total_pct_gdp' => 940,
            'debt.student_loan_pct_gdp' => 950,

            'external.forex_reserves_bn_usd' => 1000,
            'economy.gross_savings_pct_gdp' => 1010,
            'wealth.sovereign_fund_bn_usd' => 1020,
            'external.creditor_bn_usd' => 1030,
            'external.debtor_bn_usd' => 1040,
            'external.net_iip_bn_usd' => 1050,
            'investment.fdi_outflow_bn_usd' => 1060,
            'investment.fdi_inflow_bn_usd' => 1070,
            'investment.fdi_net_bn_usd' => 1080,
            'wealth.financial_assets_bn_eur' => 745,
            'wealth.financial_assets_bn_usd' => 746,
        ];

        foreach ($indicators as $row) {
            [$code, $category, $name, $definition, $unit, $valueKind, $decimals] = $row;
            $extra = $row[7] ?? [];

            Indicator::updateOrCreate(
                ['code' => $code],
                array_merge([
                    'category_id' => $categoryIds[$category],
                    'module' => 'demographics',
                    'name' => $name,
                    'description' => $definition,
                    'definition' => $definition,
                    'unit' => $unit,
                    'value_kind' => $valueKind,
                    'decimals' => $decimals,
                    'display_order' => $displayOrder[$code] ?? 1000,
                    'built_in_frequency' => 'annual',
                    'supports_disaggregation' => false,
                    'is_stock' => false,
                    'is_per_capita' => false,
                    'is_sensitive' => false,
                    'is_derived' => false,
                    'aggregation_method' => 'none',
                ], $extra)
            );
        }
    }
}
