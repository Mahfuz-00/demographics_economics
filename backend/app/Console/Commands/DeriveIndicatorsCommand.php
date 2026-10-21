<?php

namespace App\Console\Commands;

use App\Domain\Demographics\Derivation\DerivedIndicators;
use App\Models\Entity;
use Illuminate\Console\Command;

class DeriveIndicatorsCommand extends Command
{
    protected $signature = 'demographics:derive
        {--entity= : Limit to these entities (ISO code, slug or uuid; comma separated)}
        {--from= : Earliest year}
        {--to= : Latest year}';

    protected $description = 'Fill missing rates and flows from births, deaths and population';

    public function handle(DerivedIndicators $derivation): int
    {
        $entityIds = null;
        $entityOption = $this->option('entity');

        if ($entityOption !== null) {
            $keys = array_values(array_filter(array_map('trim', explode(',', (string) $entityOption))));
            $entityIds = [];

            foreach ($keys as $key) {
                $entity = Entity::findByKey($key);

                if ($entity === null) {
                    $this->error("Unknown entity [{$key}].");

                    return self::FAILURE;
                }

                $entityIds[] = $entity->id;
            }
        }

        $this->info('Deriving rates and flows from vital statistics…');

        $result = $derivation->run(
            $entityIds,
            $this->option('from') !== null ? (int) $this->option('from') : null,
            $this->option('to') !== null ? (int) $this->option('to') : null,
        );

        $this->table(['Metric', 'Value'], [
            ['Derived observations created', $result['created']],
            ['Already present (unchanged)', $result['unchanged']],
            ['Rejected', $result['rejected']],
        ]);

        $this->line('Derived values are flagged and ranked below every reported figure; a published rate is never replaced.');

        return self::SUCCESS;
    }
}
