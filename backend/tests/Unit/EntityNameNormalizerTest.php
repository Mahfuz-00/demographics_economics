<?php

namespace Tests\Unit;

use App\Support\EntityNameNormalizer;
use PHPUnit\Framework\TestCase;

class EntityNameNormalizerTest extends TestCase
{
    public function test_variants_of_one_country_name_collapse_to_a_single_key(): void
    {
        $keys = array_map(
            [EntityNameNormalizer::class, 'normalize'],
            ['Czechia', 'Czech Republic', 'Czech Rep.']
        );

        $this->assertSame(['czechia', 'czech republic', 'czech rep'], $keys);
    }

    public function test_a_misspelling_is_left_to_the_alias_table_rather_than_guessed_at(): void
    {
        // Normalisation only removes noise; it does not try to correct spelling.
        // "Czeck Republic" is unified with "Czech Republic" by a seeded alias, which
        // keeps the correction explicit and reviewable rather than buried in a rule.
        $this->assertNotSame(
            EntityNameNormalizer::normalize('Czech Republic'),
            EntityNameNormalizer::normalize('Czeck Republic')
        );
    }

    public function test_it_strips_dependency_prefixes(): void
    {
        $this->assertSame('bermuda', EntityNameNormalizer::normalize('(UK) Bermuda'));
        $this->assertSame('martinique', EntityNameNormalizer::normalize('(France) Martinique'));
        $this->assertSame('hong kong', EntityNameNormalizer::normalize('Hong Kong (China)'));
        $this->assertSame('puerto rico', EntityNameNormalizer::normalize('Puerto Rico (USA)'));
    }

    public function test_it_strips_embedded_commentary(): void
    {
        $this->assertSame(
            'russia',
            EntityNameNormalizer::normalize('Russia (Crimea in 2020 was 24,16,856)')
        );
    }

    public function test_apostrophes_do_not_split_one_name_into_two(): void
    {
        $this->assertSame(
            EntityNameNormalizer::normalize("Cote d'Ivoire"),
            EntityNameNormalizer::normalize('Cote d Ivoire')
        );
    }

    public function test_accented_and_unaccented_spellings_converge(): void
    {
        $this->assertSame(
            EntityNameNormalizer::normalize('São Tomé and Príncipe'),
            EntityNameNormalizer::normalize('Sao Tome and Principe')
        );
    }

    public function test_undecodable_bytes_do_not_break_matching(): void
    {
        $this->assertSame('cote d ivoire', EntityNameNormalizer::normalize("Cote d\u{FFFD}Ivoire"));
    }

    public function test_column_keys_keep_parenthetical_qualifiers_apart(): void
    {
        // "By year" and "By Year (Immigrants)" are different columns in the source.
        $this->assertNotSame(
            EntityNameNormalizer::columnKey('By year'),
            EntityNameNormalizer::columnKey('By Year (Immigrants)')
        );
    }

    public function test_column_keys_ignore_footnote_markers_and_case(): void
    {
        $this->assertSame(
            EntityNameNormalizer::columnKey('Country[2019]'),
            EntityNameNormalizer::columnKey('country[2019]*')
        );
    }
}
