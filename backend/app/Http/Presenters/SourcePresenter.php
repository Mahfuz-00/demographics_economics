<?php

namespace App\Http\Presenters;

use App\Models\SourceDataset;
use App\Models\SourceFetchRun;
use App\Models\SourceSnapshot;
use Illuminate\Database\Eloquent\Collection;

class SourcePresenter
{
    public static function dataset(SourceDataset $dataset): array
    {
        return [
            'code' => $dataset->code,
            'name' => $dataset->name,
            'description' => $dataset->description,
            'access_method' => $dataset->access_method,
            'update_frequency' => $dataset->update_frequency,
            'expected_latency_days' => $dataset->expected_latency_days,
            'adapter_key' => $dataset->adapter_key,
            'endpoint_url' => $dataset->endpoint_url,
            'documentation_url' => $dataset->documentation_url,
            'enabled' => $dataset->enabled,
            'last_checked_at' => $dataset->last_checked_at?->toIso8601String(),
            'last_success_at' => $dataset->last_success_at?->toIso8601String(),
            'next_run_at' => $dataset->next_run_at?->toIso8601String(),
            'source' => $dataset->relationLoaded('source') ? ObservationPresenter::source($dataset->source) : null,
        ];
    }

    public static function run(SourceFetchRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration_ms' => $run->duration_ms,
            'http_status' => $run->http_status,
            'bytes' => $run->bytes,
            'records_seen' => $run->records_seen,
            'records_new' => $run->records_new,
            'records_revised' => $run->records_revised,
            'records_unchanged' => $run->records_unchanged,
            'records_rejected' => $run->records_rejected,
            'triggered_by' => $run->triggered_by,
            'error_class' => $run->error_class,
            'error_message' => $run->error_message,
            'dataset' => $run->relationLoaded('dataset') && $run->dataset !== null
                ? ['code' => $run->dataset->code, 'name' => $run->dataset->name]
                : null,
        ];
    }

    public static function snapshot(SourceSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'url' => $snapshot->url,
            'http_status' => $snapshot->http_status,
            'content_type' => $snapshot->content_type,
            'byte_size' => $snapshot->byte_size,
            'content_hash' => $snapshot->content_hash,
            'storage_path' => $snapshot->storage_path,
            'fetched_at' => $snapshot->fetched_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, SourceDataset>  $datasets
     */
    public static function datasetCollection(Collection $datasets): array
    {
        return $datasets->map(fn (SourceDataset $dataset): array => self::dataset($dataset))->all();
    }
}
