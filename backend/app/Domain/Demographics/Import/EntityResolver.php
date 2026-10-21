<?php

namespace App\Domain\Demographics\Import;

use App\Models\Entity;
use App\Models\EntityNameAlias;
use App\Support\EntityNameNormalizer;

/**
 * Maps an upstream name onto an entity.
 *
 * Upstream text is matched against the seeded alias table after normalisation, so
 * "Czeck Republic", "Czech Republic" and "Czechia" all reach the same country, and
 * "Russia (Crimea in 2020 was 24,16,856)" is recognised as Russia. When nothing
 * matches, the caller is told so and quarantines the row rather than guessing.
 */
class EntityResolver
{
    /** @var array<string, Entity> */
    private array $byNormalizedAlias = [];

    private bool $loaded = false;

    public function resolve(?string $name): ?Entity
    {
        $this->load();

        $normalized = EntityNameNormalizer::normalize($name);

        if ($normalized === '') {
            return null;
        }

        if (isset($this->byNormalizedAlias[$normalized])) {
            return $this->byNormalizedAlias[$normalized];
        }

        return $this->resolveByIsoCode($name);
    }

    /**
     * Resolution is a first pass over the alias table, which is small (a few hundred
     * rows) and read far more often than it changes.
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        EntityNameAlias::query()
            ->with('entity')
            ->orderByDesc('confidence')
            ->orderBy('entity_id')
            ->get()
            ->each(function (EntityNameAlias $alias): void {
                if ($alias->entity === null) {
                    return;
                }

                $this->byNormalizedAlias[$alias->normalized_alias] ??= $alias->entity;
            });

        $this->loaded = true;
    }

    private function resolveByIsoCode(?string $name): ?Entity
    {
        $code = strtoupper(trim((string) $name));

        if (! in_array(strlen($code), [2, 3], true)) {
            return null;
        }

        return Entity::query()
            ->where(fn ($query) => $query
                ->where('iso_alpha2', $code)
                ->orWhere('iso_alpha3', $code))
            ->first();
    }
}
