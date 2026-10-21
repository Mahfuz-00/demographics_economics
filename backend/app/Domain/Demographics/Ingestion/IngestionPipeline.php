<?php

namespace App\Domain\Demographics\Ingestion;

use App\Domain\Demographics\Ingestion\Dto\RawDataset;
use App\Models\IngestionBatch;
use App\Models\SourceDataset;
use App\Models\SourceFetchRun;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Runs one source end to end: fetch, snapshot, normalise, write, report.
 *
 * Every attempt leaves a `source_fetch_runs` row behind — including failures — so
 * "when was this last tried, and what happened" is always answerable.
 */
class IngestionPipeline
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly ObservationWriter $writer,
    ) {}

    /**
     * @param  array<string, mixed>  $options  passed through to the adapter
     */
    public function run(SourceDataset $dataset, string $triggeredBy = 'cli', ?int $userId = null, array $options = []): SourceFetchRun
    {
        $run = SourceFetchRun::create([
            'source_dataset_id' => $dataset->id,
            'status' => 'running',
            'started_at' => now(),
            'triggered_by' => $triggeredBy,
            'triggered_by_user_id' => $userId,
        ]);

        $startedAt = microtime(true);

        try {
            $adapter = $this->registry->for($dataset);
            $fetched = $adapter->fetch($dataset, $options);

            $raw = new RawDataset(
                dataset: $fetched->dataset,
                snapshot: $fetched->snapshot,
                run: $run,
                payload: $fetched->payload,
                meta: $fetched->meta,
            );

            $batch = IngestionBatch::create([
                'source_dataset_id' => $dataset->id,
                'fetch_run_id' => $run->id,
                'kind' => 'api',
                'status' => 'running',
                'started_at' => now(),
            ]);

            $result = $this->writer->write($batch, $adapter->normalize($raw));

            $batch->update([
                'status' => $result->rejected > 0 ? 'partial' : 'success',
                'observations_created' => $result->created,
                'observations_unchanged' => $result->unchanged,
                'observations_superseded' => $result->superseded,
                'conflicts' => $result->conflicts,
                'finished_at' => now(),
                'report' => [
                    'adapter' => $adapter->key(),
                    'write' => $result->toArray(),
                    'rejections' => array_slice($result->rejections, 0, 50),
                    'meta' => $raw->meta,
                ],
            ]);

            $run->update([
                'status' => $result->rejected > 0 ? 'partial' : 'success',
                'finished_at' => now(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'http_status' => $raw->meta['http_status'] ?? null,
                'bytes' => $raw->snapshot->byte_size,
                'records_seen' => $result->created + $result->unchanged + $result->superseded,
                'records_new' => $result->created,
                'records_revised' => $result->superseded,
                'records_unchanged' => $result->unchanged,
                'records_rejected' => $result->rejected,
            ]);

            $dataset->update([
                'last_checked_at' => now(),
                'last_success_at' => now(),
                'next_run_at' => $this->nextRunAt($dataset),
            ]);

            return $run->refresh();
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
                'log' => $exception->getTraceAsString(),
            ]);

            $dataset->update(['last_checked_at' => now()]);

            throw $exception;
        }
    }

    private function nextRunAt(SourceDataset $dataset): ?CarbonInterface
    {
        $now = now();

        return match ($dataset->update_frequency) {
            'daily' => $now->addDay(),
            'weekly' => $now->addWeek(),
            'monthly' => $now->addMonth(),
            'quarterly' => $now->addMonths(3),
            'semiannual' => $now->addMonths(6),
            'annual' => $now->addYear(),
            default => null,
        };
    }
}
