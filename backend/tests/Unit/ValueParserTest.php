<?php

namespace Tests\Unit;

use App\Domain\Demographics\Import\ValueParser;
use PHPUnit\Framework\TestCase;

/**
 * The parser is the boundary between a spreadsheet written for humans and a database
 * that must not hold invented numbers. These cases are taken from the real source
 * file.
 */
class ValueParserTest extends TestCase
{
    private ValueParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ValueParser;
    }

    public function test_it_reads_thousands_separated_numbers(): void
    {
        $result = $this->parser->number('"1,412,600,000"');

        $this->assertTrue($result->isValue());
        $this->assertSame(1412600000.0, $result->value);
    }

    public function test_it_reads_indian_style_grouping(): void
    {
        $result = $this->parser->number('1,00,000');

        $this->assertTrue($result->isValue());
        $this->assertSame(100000.0, $result->value);
    }

    public function test_a_footnote_marker_makes_the_value_preliminary(): void
    {
        $result = $this->parser->number('2024*');

        $this->assertTrue($result->isValue());
        $this->assertSame(2024.0, $result->value);
        $this->assertSame('preliminary', $result->status);
        $this->assertNotNull($result->note);
    }

    public function test_an_estimator_qualifier_is_flagged_as_an_estimate(): void
    {
        $result = $this->parser->number('3,000,000 est.');

        $this->assertTrue($result->isValue());
        $this->assertSame('estimated', $result->status);
        $this->assertTrue($result->isEstimate);
    }

    public function test_it_splits_a_value_with_an_embedded_reference_year(): void
    {
        $result = $this->parser->number('0.93/2025');

        $this->assertTrue($result->isValue());
        $this->assertSame(0.93, $result->value);
        $this->assertSame(2025, $result->year);
    }

    public function test_an_open_ended_comparison_is_kept_with_a_note(): void
    {
        $result = $this->parser->number('<2008');

        $this->assertTrue($result->isValue());
        $this->assertSame(2008.0, $result->value);
        $this->assertStringContainsString('open-ended', (string) $result->note);
    }

    public function test_a_range_is_refused_rather_than_collapsed_to_one_end(): void
    {
        $result = $this->parser->number('55-74');

        $this->assertTrue($result->isError());
        $this->assertSame('value_is_range', $result->errorCode);
    }

    public function test_a_spreadsheet_error_token_is_quarantined_not_stored(): void
    {
        $result = $this->parser->number('#REF!');

        $this->assertTrue($result->isError());
        $this->assertSame('broken_reference', $result->errorCode);
    }

    public function test_prose_is_refused_rather_than_parsed_for_a_number(): void
    {
        $result = $this->parser->number('1,00,000 Websites Shutdown so far');

        $this->assertTrue($result->isError());
        $this->assertSame('unparseable_value', $result->errorCode);
    }

    public function test_a_dash_is_a_declared_absence(): void
    {
        $result = $this->parser->number('-');

        $this->assertTrue($result->isMissing());
        $this->assertNull($result->value);
    }

    public function test_an_empty_cell_is_recorded_as_not_collected(): void
    {
        $result = $this->parser->number('   ');

        $this->assertTrue($result->isMissing());
        $this->assertSame('not_collected', $result->missingReason);
    }

    public function test_zero_is_a_value_and_not_treated_as_missing(): void
    {
        $result = $this->parser->number('0');

        $this->assertTrue($result->isValue());
        $this->assertSame(0.0, $result->value);
    }

    public function test_it_reads_a_year_column_and_keeps_the_latest_of_several(): void
    {
        $result = $this->parser->year('2025/2024');

        $this->assertTrue($result->isValue());
        $this->assertSame(2025, $result->year);
        $this->assertStringContainsString('several years', (string) $result->note);
    }

    public function test_a_year_column_that_holds_a_comparison_is_flagged(): void
    {
        $result = $this->parser->year('<1998');

        $this->assertTrue($result->isValue());
        $this->assertSame(1998, $result->year);
    }

    public function test_the_original_text_is_preserved_alongside_the_parsed_number(): void
    {
        $result = $this->parser->number('"10,839"');

        $this->assertSame('"10,839"', $result->rawValue);
    }
}
