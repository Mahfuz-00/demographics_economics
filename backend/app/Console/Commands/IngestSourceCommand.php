<?php

namespace App\Console\Commands;

use App\Domain\Demographics\Ingestion\AdapterRegistry;
use App\Domain\Demographics\Ingestion\IngestionPipeline;
use App\Models\Entity;
use App\Models\SourceDataset;
use Illuminate\Console\Command;
use Throwable;

class IngestSourceCommand extends Command
{
    protected $signature = 'demographics:ingest
        {dataset? : Dataset code, or "all" for every enabled dataset}
        {--user= : Attribute the run to a user id}
        {--sync : Run inline instead of queueing}
        {--entity= : Only this entity (ISO code, slug or uuid); repeatable via commas}
        {--limit= : Cap how many entities a scraping adapter processes}
        {--list : List registered datasets and adapters}';

    protected $description = 'Fetch and ingest data from a configured source';

    public function handle(IngestionPipeline $pipeline, AdapterRegistry $registry): int
    {
        if ($this->option('list')) {
            return $this->listDatasets($registry);
        }

        $options = $this->adapterOptions();

        if ($options === null) {
            return self::FAILURE;
        }

        $code = $this->argument('dataset') ?? 'all';

        $datasets = $code === 'all'
            ? SourceDataset::query()->enabled()->whereNotNull('adapter_key')->orderBy('code')->get()
            : SourceDataset::query()->where('code', $code)->get();

        if ($datasets->isEmpty()) {
            $this->error("No dataset found for [{$code}].");

            return self::FAILURE;
        }

        $failures = 0;

        foreach ($datasets as $dataset) {
            if ($dataset->adapter_key === null) {
                $this->warn("Skipping {$dataset->code}: no adapter (manual upload source).");

                continue;
            }

            $this->info("Ingesting {$dataset->code} via {$dataset->adapter_key} …");

            try {
                $run = $pipeline->run(
                    $dataset,
                    triggeredBy: 'cli',
                    userId: $this->option('user') !== null ? (int) $this->option('user') : null,
                    options: $options,
                );
            } catch (Throwable $exception) {
                $failures++;
                $this->error("  failed: {$exception->getMessage()}");

                continue;
            }

            $this->table(['Metric', 'Value'], [
                ['Status', $run->status],
                ['New observations', $run->records_new],
                ['Revised', $run->records_revised],
                ['Unchanged', $run->records_unchanged],
                ['Rejected', $run->records_rejected],
                ['Duration (ms)', $run->duration_ms],
            ]);
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null null when an entity key could not be resolved
     */
    private function adapterOptions(): ?array
    {
        $options = [];

        if ($this->option('limit') !== null) {
            $options['limit'] = (int) $this->option('limit');
        }

        $entityOption = $this->option('entity');

        if ($entityOption !== null) {
            $keys = array_values(array_filter(array_map('trim', explode(',', (string) $entityOption))));
            $ids = [];

            foreach ($keys as $key) {
                $entity = Entity::findByKey($key);

                if ($entity === null) {
                    $this->error("Unknown entity [{$key}].");

                    return null;
                }

                $ids[] = $entity->id;
            }

            $options['entity_ids'] = $ids;
        }

        return $options;
    }

    private function listDatasets(AdapterRegistry $registry): int
    {
        $rows = SourceDataset::query()
            ->with('source')
            ->orderBy('code')
            ->get()
            ->map(fn (SourceDataset $dataset): array => [
                $dataset->code,
                $dataset->source?->publisher_short ?? '—',
                $dataset->adapter_key ?? '—',
                $dataset->update_frequency,
                $dataset->enabled ? 'yes' : 'no',
                $dataset->last_checked_at?->toDateString() ?? 'never',
            ]);

        $this->table(['Dataset', 'Source', 'Adapter', 'Frequency', 'Enabled', 'Last checked'], $rows);
        $this->line('Registered adapters: '.implode(', ', $registry->keys()));

        return self::SUCCESS;
    }
}
