<?php

namespace Tests\Feature;

use App\Domain\Demographics\Import\EntityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Name resolution is where an unnamed spreadsheet meets a canonical entity list.
 * These are the exact strings lifted from the source file, including its typos.
 */
class EntityResolverTest extends TestCase
{
    use RefreshDatabase;

    private EntityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->resolver = app(EntityResolver::class);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function awkwardNames(): array
    {
        return [
            'plain name' => ['India', 'IND'],
            'misspelled Czechia' => ['Czeck Republic', 'CZE'],
            'abbreviated Czechia' => ['Czech Rep.', 'CZE'],
            'misspelled Azerbaijan' => ['Azerbijan', 'AZE'],
            'misspelled Ethiopia' => ['Ethopia', 'ETH'],
            'misspelled Kyrgyzstan' => ['Krygyzstan', 'KGZ'],
            'misspelled Grenada' => ['Greneda', 'GRD'],
            'misspelled Australia' => ['Australa', 'AUS'],
            'misspelled El Salvador' => ['El Slavador', 'SLV'],
            'misspelled Puerto Rico' => ['Pueto Rico', 'PRI'],
            'misspelled Bosnia' => ['Bosnia and Herzegovnia', 'BIH'],
            'country plus commentary' => ['Russia (Crimea in 2020 was 24,16,856)', 'RUS'],
            'dependency prefix' => ['(UK) Bermuda', 'BMU'],
            'dependency suffix' => ['Puerto Rico (USA)', 'PRI'],
            'territory with owner' => ['(France) Martinique', 'MTQ'],
            'trailing whitespace' => ['Australia ', 'AUS'],
            'alternative official name' => ['Ivory Coast', 'CIV'],
            'korean comma form' => ['Korea, South', 'KOR'],
            'congo disambiguation' => ['DR Congo', 'COD'],
            'congo republic' => ['Congo Republic', 'COG'],
            'the bahamas' => ['The Bahamas', 'BHS'],
            'timor' => ['East Timor', 'TLS'],
            'swaziland' => ['Swaziland', 'SWZ'],
            'vatican' => ['Vatican City', 'VAT'],
            'taiwan' => ['Taiwan', 'TWN'],
            'kosovo' => ['Kosovo', 'XKX'],
        ];
    }

    #[DataProvider('awkwardNames')]
    public function test_it_resolves_names_as_the_source_writes_them(string $input, string $expectedIso3): void
    {
        $entity = $this->resolver->resolve($input);

        $this->assertNotNull($entity, "Expected \"{$input}\" to resolve to {$expectedIso3}.");
        $this->assertSame($expectedIso3, $entity->iso_alpha3);
    }

    public function test_it_resolves_an_entity_by_bare_iso_code(): void
    {
        $this->assertSame('IND', $this->resolver->resolve('IND')->iso_alpha3);
        $this->assertSame('IND', $this->resolver->resolve('IN')->iso_alpha3);
    }

    public function test_it_resolves_aggregate_rows_to_aggregate_entities(): void
    {
        $this->assertSame('region', $this->resolver->resolve('Europe')->entity_type);
        $this->assertSame('aggregate', $this->resolver->resolve('World')->entity_type);
        $this->assertSame('aggregate', $this->resolver->resolve('Global')->entity_type);
    }

    public function test_it_returns_nothing_rather_than_guessing_for_unknown_names(): void
    {
        $this->assertNull($this->resolver->resolve('Dates End up in Break Up (Developed Countries)'));
        $this->assertNull($this->resolver->resolve('Video Games'));
        $this->assertNull($this->resolver->resolve('North America + Europe + Oceania'));
        $this->assertNull($this->resolver->resolve(''));
    }
}
