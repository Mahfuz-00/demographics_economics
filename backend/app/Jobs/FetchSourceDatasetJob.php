<?php

namespace App\Jobs;

use App\Domain\Demographics\Ingestion\IngestionPipeline;
use App\Models\SourceDataset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one source dataset. Queued rather than inline so a slow upstream cannot block
 * a request, and so retries and backoff are handled by the queue.
 */
class FetchSourceDatasetJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public readonly int $sourceDatasetId,
        public readonly string $triggeredBy = 'schedule',
        public readonly ?int $userId = null,
    ) {}

    public function handle(IngestionPipeline $pipeline): void
    {
        $dataset = SourceDataset::find($this->sourceDatasetId);

        if ($dataset === null || ! $dataset->enabled) {
            return;
        }

        $pipeline->run($dataset, $this->triggeredBy, $this->userId);
    }
}
