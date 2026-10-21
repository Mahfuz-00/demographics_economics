<?php

namespace App\Domain\Demographics\Discovery;

use App\Domain\Demographics\Ingestion\IngestionPipeline;
use App\Models\Entity;
use App\Models\IngestionBatch;
use App\Models\SourceCandidate;
use App\Models\SourceDataset;
use App\Models\SourceFetchRun;
use Throwable;

/**
 * The "get data for this country" action.
 *
 * Runs the scraper for one entity, then — only if nothing usable came back — searches
 * the web and records what it finds as candidates for review. Nothing from the search
 * is written as data.
 */
class CountryDataFetcher
{
    public function __construct(
        private readonly IngestionPipeline $pipeline,
        private readonly WebSearchService $search,
    ) {}

    /**
     * @return array{
     *     run: SourceFetchRun|null,
     *     created: int,
     *     unchanged: int,
     *     candidates: array<int, SourceCandidate>,
     *     searched: bool,
     *     error: string|null
     * }
     */
    public function fetch(Entity $entity, string $datasetCode, ?int $userId = null): array
    {
        $dataset = SourceDataset::query()->where('code', $datasetCode)->firstOrFail();
        $error = null;
        $run = null;
        $batch = null;

        try {
            $run = $this->pipeline->run($dataset, 'api', $userId, ['entity_ids' => [$entity->id]]);
            $batch = IngestionBatch::query()->where('fetch_run_id', $run->id)->first();
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        $meta = $batch?->report['meta'] ?? [];
        $noData = ($meta['entities_without_data'] ?? []) !== [];
        $producedNothing = $run !== null && ($run->records_new + $run->records_unchanged) === 0;

        $searched = false;
        $candidates = [];

        if ($error !== null || $noData || $producedNothing) {
            $searched = true;
            $candidates = $this->discover($entity);
        }

        return [
            'run' => $run,
            'created' => $run?->records_new ?? 0,
            'unchanged' => $run?->records_unchanged ?? 0,
            'candidates' => $candidates,
            'searched' => $searched,
            'error' => $error,
        ];
    }

    /**
     * @return array<int, SourceCandidate>
     */
    private function discover(Entity $entity): array
    {
        $templates = config('demographics.discovery.query_templates', []);
        $candidates = [];

        foreach ($templates as $template) {
            $query = str_replace('{country}', $entity->name, (string) $template);

            foreach ($this->search->search($query) as $result) {
                $candidate = SourceCandidate::query()->firstOrCreate(
                    ['entity_id' => $entity->id, 'url' => $result['url']],
                    [
                        'query' => $query,
                        'provider' => (string) config('demographics.discovery.search_provider', 'duckduckgo'),
                        'title' => $result['title'],
                        'snippet' => $result['snippet'],
                        'host' => $result['host'],
                        'status' => 'pending',
                    ]
                );

                $candidates[$candidate->id] = $candidate;
            }
        }

        return array_values($candidates);
    }
}
