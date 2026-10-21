<?php

namespace App\Domain\Demographics\Ingestion;

use App\Models\SourceDataset;
use App\Models\SourceSnapshot;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps the exact bytes a value was parsed from.
 *
 * Snapshots are content-addressed and deduplicated: re-fetching an unchanged
 * payload stores nothing new, and a changed payload is kept alongside the old one
 * so a historical value can always be re-parsed against what was actually served.
 */
class SnapshotStore
{
    public function store(
        SourceDataset $dataset,
        string $contents,
        ?string $url = null,
        ?int $httpStatus = null,
        ?int $fetchRunId = null,
        ?string $contentType = null,
    ): SourceSnapshot {
        $hash = hash('sha256', $contents);

        $existing = SourceSnapshot::query()
            ->where('source_dataset_id', $dataset->id)
            ->where('content_hash', $hash)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $config = config('demographics.snapshots');
        $path = trim($config['path'], '/').'/'.$dataset->code.'/'.$hash.'.gz';
        $payload = $config['compress'] ? gzencode($contents, 6) : $contents;

        Storage::disk($config['disk'])->put($path, $payload);

        return SourceSnapshot::create([
            'source_dataset_id' => $dataset->id,
            'fetch_run_id' => $fetchRunId,
            'url' => $url,
            'http_status' => $httpStatus,
            'content_type' => $contentType,
            'byte_size' => strlen($contents),
            'content_hash' => $hash,
            'storage_path' => $path,
            'fetched_at' => now(),
            'is_parsed' => false,
        ]);
    }
}
