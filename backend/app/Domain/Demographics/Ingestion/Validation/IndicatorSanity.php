<?php

namespace App\Domain\Demographics\Ingestion\Validation;

/**
 * Plausibility bounds per indicator.
 *
 * Scraped tables are not always well-formed: a column can drift out of alignment, or a
 * rate can sit in a count column. The result is a value that is syntactically fine but
 * impossible — a life expectancy of −8.3, a total fertility rate of 27.6.
 *
 * This is a guard, not a transformation: values outside the bounds are dropped and
 * counted, never clamped or rounded into something plausible-looking. The bounds are
 * deliberately wide; they catch nonsense, not policy.
 */
class IndicatorSanity
{
    /**
     * indicator code => [minimum, maximum]
     */
    private const BOUNDS = [
        'pop.total' => [1, 2_000_000_000],
        'pop.peak' => [1, 2_000_000_000],
        'pop.age_65plus_share' => [0, 100],
        'pop.median_age' => [10, 70],
        'pop.growth_rate' => [-100, 100],
        'pop.native_share' => [0, 100],
        'pop.natural_change.rate' => [-100, 100],
        'pop.natural_change.absolute' => [-50_000_000, 100_000_000],
        'pop.change.annual' => [-50_000_000, 100_000_000],

        'birth.count.annual' => [0, 100_000_000],
        'birth.rate.crude' => [0, 100],
        'birth.nonmarital_share' => [0, 100],
        'birth.foreign_mother_share' => [0, 100],

        'death.count.annual' => [0, 100_000_000],
        'death.rate.crude' => [0, 100],
        'death.infant_rate' => [0, 500],
        'death.life_expectancy' => [10, 120],

        // The highest total fertility rate ever recorded is about 7 (Niger). A ceiling of
        // 8 is generous, and it is what catches a mis-aligned column: on the Japan and
        // United States articles the scraper was reading the crude death rate instead,
        // producing "fertility" of 9.8 to 12.9 that looked like a plausible number.
        'fert.tfr' => [0.5, 8],
        'fert.tfr.minimum' => [0.5, 8],
        'fert.childlessness' => [0, 100],

        'mig.net_annual' => [-50_000_000, 100_000_000],
        'mig.rate.crude' => [-100, 100],
        'mig.foreign_born_share' => [0, 100],

        'household.divorce_rate' => [0, 100],
        'household.single_parent_share' => [0, 100],

        // Wide, because a year-on-year change can legitimately be very large when the
        // base year was small; these catch arithmetic blow-ups, not policy.
        'birth.change.pct' => [-1000, 1000],
        'death.change.pct' => [-1000, 1000],
        'pop.natural_change.change_pct' => [-1000, 1000],
        'pop.change.natural_share' => [-2000, 2000],

        /*
         * Economy, Debt and Wealth.
         *
         * The debt bounds are deliberately loose — Luxembourg's external debt really is
         * several thousand percent of its GDP, because it is a financial centre — but
         * they are bounds, so a mis-scaled column cannot land. The currency bounds are
         * set well above any real figure: the largest national GDP is about 30,000bn,
         * so anything past 200,000bn is a units error, not a country.
         */
        'economy.gdp.nominal_bn' => [0, 200_000],
        'economy.gdp.peak_bn' => [0, 200_000],
        'economy.gdp.ppp_bn' => [0, 500_000],
        'economy.gdp.per_capita.nominal' => [0, 1_000_000],
        'economy.gdp.per_capita.peak' => [0, 1_000_000],
        'economy.gdp.per_capita.ppp' => [0, 2_000_000],
        'economy.gross_savings_pct_gdp' => [-100, 100],
        'economy.informal_economy_share' => [0, 100],

        'debt.external_bn_usd' => [0, 1_000_000],
        'debt.external_pct_gdp' => [0, 20_000],
        'debt.public_pct_gdp' => [0, 20_000],
        'debt.household_pct_gdp' => [0, 20_000],
        'debt.corporate_pct_gdp' => [0, 20_000],
        'debt.total_pct_gdp' => [0, 20_000],
        'debt.student_loan_pct_gdp' => [0, 100],

        'wealth.current_bn_usd' => [0, 1_000_000],
        'wealth.peak_bn_usd' => [0, 1_000_000],
        'wealth.to_gdp_ratio' => [0, 1_000],
        'wealth.sovereign_fund_bn_usd' => [0, 100_000],
        'external.forex_reserves_bn_usd' => [0, 100_000],
        'external.net_iip_bn_usd' => [-1_000_000, 1_000_000],
        'investment.fdi_outflow_bn_usd' => [0, 1_000_000],
        'investment.fdi_inflow_bn_usd' => [0, 1_000_000],
        'investment.fdi_net_bn_usd' => [-1_000_000, 1_000_000],
    ];

    public static function isPlausible(string $indicatorCode, ?float $value): bool
    {
        if ($value === null) {
            return false;
        }

        $bounds = self::BOUNDS[$indicatorCode] ?? null;

        if ($bounds === null) {
            // No declared bounds: accept, but only finite numbers.
            return is_finite($value);
        }

        return is_finite($value) && $value >= $bounds[0] && $value <= $bounds[1];
    }
}
