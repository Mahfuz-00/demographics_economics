<?php

namespace Tests\Unit;

use App\Domain\Demographics\Ingestion\Adapters\WikitableParser;
use PHPUnit\Framework\TestCase;

/**
 * The vital-statistics tables vary between articles, so the parser is exercised
 * against the markup the real pages actually contain: styling attributes before the
 * value, footnote references inside cells, the typographic minus sign, and rowspan
 * grouping.
 */
class WikitableParserTest extends TestCase
{
    private WikitableParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new WikitableParser;
    }

    private function indiaStyleTable(): string
    {
        return <<<'WIKITEXT'
{| class="wikitable sortable" style="text-align:right;"
|+ United Nations, World Population Prospects: The 2024 revision – India<ref>UN DESA</ref>
|-
! scope="col" | Year
! scope="col" style="text-align: center;" | Mid-year population
! scope="col" | Births per year
! scope="col" | Deaths per year
! scope="col" | Crude birth rate<br />(per 1000)
! scope="col" | Crude death rate<br />(per 1000)
! scope="col" | Total Fertility rate
! scope="col" | Infant mortality (per 1000)
|-
| 1950
| style="color:red" |346,278,817
| style="color:red" |15,287,998
| style="color:blue" | 7,852,219
| 44.15
| style="color:red"|22.68
| style="text-align:right;" |5.73
| style="color:red" | 181.2
|-
| 1951
| 353,870,058
| 15,663,440
| 7,973,004
| 44.26
| 22.53
| 5.77
| 180.1
|-
| 1952
| 361,874,904
| 16,082,888
| 8,048,990
| 44.44
| 22.24
| 5.82
| 177.5
|}
WIKITEXT;
    }

    public function test_it_reads_a_table_with_styling_attributes_and_footnotes(): void
    {
        $tables = $this->parser->parse($this->indiaStyleTable());

        $this->assertCount(1, $tables);

        $table = $tables[0];

        $this->assertSame('Year', $table['headers'][0]);
        $this->assertSame('Mid-year population', $table['headers'][1]);
        $this->assertSame('Births per year', $table['headers'][2]);
        $this->assertSame('Crude birth rate (per 1000)', $table['headers'][4]);

        $this->assertCount(3, $table['rows']);

        // Attributes are stripped from the value, not left in it.
        $this->assertSame('1950', $table['rows'][0][0]);
        $this->assertSame('346,278,817', $table['rows'][0][1]);
        $this->assertSame('44.15', $table['rows'][0][4]);
        $this->assertSame('5.73', $table['rows'][0][6]);
    }

    public function test_it_normalises_the_typographic_minus_sign(): void
    {
        $wikitext = <<<'WIKITEXT'
{|
! Year
! Natural change
|-
| 2021
| −0.4
|}
WIKITEXT;

        $table = $this->parser->parse($wikitext)[0];

        $this->assertSame('-0.4', $table['rows'][0][1]);
    }

    public function test_it_carries_a_rowspan_value_into_the_following_rows(): void
    {
        $wikitext = <<<'WIKITEXT'
{|
! Year
! Births
|-
| rowspan="2" | 1990
| 1000
|-
| 1100
|}
WIKITEXT;

        $table = $this->parser->parse($wikitext)[0];

        $this->assertSame('1990', $table['rows'][0][0]);
        $this->assertSame('1000', $table['rows'][0][1]);

        // The second row inherits the spanned year and supplies only its own value.
        $this->assertSame('1990', $table['rows'][1][0]);
        $this->assertSame('1100', $table['rows'][1][1]);
    }

    public function test_it_does_not_truncate_a_table_containing_a_nested_table(): void
    {
        $wikitext = <<<'WIKITEXT'
{|
! Year
! Births
|-
| 2000
| {|
  | nested
  |}
|-
| 2001
| 2000
|}
WIKITEXT;

        $tables = $this->parser->parse($wikitext);

        $this->assertCount(1, $tables);
        $this->assertSame('2001', $tables[0]['rows'][1][0]);
        $this->assertSame('2000', $tables[0]['rows'][1][1]);
    }

    public function test_it_ignores_text_that_is_not_a_table(): void
    {
        $this->assertSame([], $this->parser->parse('Just some prose about demographics.'));
    }

    public function test_it_unwraps_a_formatnum_template_instead_of_dropping_the_value(): void
    {
        // Several articles wrap every figure in {{formatnum:...}}. Stripping the
        // template wholesale silently deleted the number and shifted the row.
        $wikitext = <<<'WIKITEXT'
{|
! Year
! Population
|-
| 1600
| {{formatnum:135000000}}
|}
WIKITEXT;

        $table = $this->parser->parse($wikitext)[0];

        $this->assertSame('135000000', $table['rows'][0][1]);
    }
}
