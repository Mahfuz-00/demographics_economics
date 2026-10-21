<?php

namespace Database\Seeders;

use App\Models\Entity;
use App\Models\EntityNameAlias;
use App\Support\EntityNameNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EntitySeeder extends Seeder
{
    public function run(): void
    {
        $data = require database_path('seed-data/entities.php');

        $regionIds = [];

        foreach ($data['regions'] as $region) {
            [$code, $name, $continent, $type] = $region;
            $extraAliases = $region[4] ?? [];

            $entity = Entity::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'entity_type' => $type,
                    'continent' => $continent,
                    'is_aggregate' => in_array($type, ['aggregate', 'region'], true),
                    'metadata' => ['code' => $code],
                ]
            );

            $regionIds[$name] = $entity->id;
            $this->addAlias($entity, $name, 'seed');

            foreach ($extraAliases as $alias) {
                $this->addAlias($entity, $alias, 'alias_list');
            }
        }

        foreach ($data['countries'] as $row) {
            [$alpha2, $alpha3, $numeric, $name, $continent, $subregion, $type, $sovereign] = $row;

            $entity = Entity::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'iso_alpha2' => $alpha2,
                    'iso_alpha3' => $alpha3,
                    'iso_numeric' => $numeric,
                    'name' => $name,
                    'entity_type' => $type,
                    'continent' => $continent,
                    'subregion' => $subregion,
                    'is_aggregate' => false,
                ]
            );

            if ($continent && isset($regionIds[$continent]) && $entity->parent_entity_id === null) {
                $entity->parent_entity_id = $regionIds[$continent];
                $entity->save();
            }

            $this->addAlias($entity, $name, 'seed');

            if ($alpha3 && isset($data['aliases'][$alpha3])) {
                foreach ($data['aliases'][$alpha3] as $alias) {
                    $this->addAlias($entity, $alias, 'alias_list');
                }
            }
        }

        $this->linkSovereignties($data['countries']);
    }

    private function linkSovereignties(array $countries): void
    {
        $byAlpha3 = Entity::query()
            ->whereNotNull('iso_alpha3')
            ->get()
            ->keyBy('iso_alpha3');

        foreach ($countries as $row) {
            $sovereignAlpha3 = $row[7];

            if (! $sovereignAlpha3) {
                continue;
            }

            $entity = Entity::where('slug', Str::slug($row[3]))->first();
            $sovereign = $byAlpha3->get($sovereignAlpha3);

            if ($entity && $sovereign && $entity->sovereignty_entity_id === null) {
                $entity->sovereignty_entity_id = $sovereign->id;
                $entity->save();
            }
        }
    }

    private function addAlias(Entity $entity, string $alias, string $hint): void
    {
        $normalized = EntityNameNormalizer::normalize($alias);

        if ($normalized === '') {
            return;
        }

        EntityNameAlias::updateOrCreate(
            ['normalized_alias' => $normalized, 'entity_id' => $entity->id],
            ['alias' => $alias, 'source_hint' => $hint, 'confidence' => 1.0]
        );
    }
}
