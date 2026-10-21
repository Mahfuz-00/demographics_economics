<?php

namespace Tests\Unit;

use App\Domain\Demographics\Ingestion\Validation\IndicatorSanity;
use PHPUnit\Framework\TestCase;

class IndicatorSanityTest extends TestCase
{
    public function test_it_accepts_ordinary_values(): void
    {
        $this->assertTrue(IndicatorSanity::isPlausible('pop.total', 1_450_935_791));
        $this->assertTrue(IndicatorSanity::isPlausible('fert.tfr', 1.98));
        $this->assertTrue(IndicatorSanity::isPlausible('death.life_expectancy', 72.0));
        $this->assertTrue(IndicatorSanity::isPlausible('death.rate.crude', 6.6));
    }

    public function test_it_rejects_values_a_misaligned_column_produces(): void
    {
        // Real cases seen while scraping: a life expectancy of −8.3 and a total
        // fertility rate of 27.6, both from column drift in one table.
        $this->assertFalse(IndicatorSanity::isPlausible('death.life_expectancy', -8.3));
        $this->assertFalse(IndicatorSanity::isPlausible('fert.tfr', 27.6));
        $this->assertFalse(IndicatorSanity::isPlausible('mig.rate.crude', -14842.0));
    }

    public function test_it_rejects_a_rate_that_landed_in_a_count_column(): void
    {
        $this->assertFalse(IndicatorSanity::isPlausible('birth.rate.crude', 1200.0));
        $this->assertTrue(IndicatorSanity::isPlausible('birth.rate.crude', 44.15));
    }

    public function test_it_rejects_absence_rather_than_treating_it_as_zero(): void
    {
        $this->assertFalse(IndicatorSanity::isPlausible('pop.total', null));
    }

    public function test_an_indicator_without_declared_bounds_still_rejects_infinities(): void
    {
        $this->assertTrue(IndicatorSanity::isPlausible('unknown.indicator', 12.5));
        $this->assertFalse(IndicatorSanity::isPlausible('unknown.indicator', INF));
        $this->assertFalse(IndicatorSanity::isPlausible('unknown.indicator', NAN));
    }
}
