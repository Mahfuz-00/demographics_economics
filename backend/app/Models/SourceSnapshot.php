<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceSnapshot extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'is_parsed' => 'boolean',
            'byte_size' => 'integer',
            'http_status' => 'integer',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(SourceDataset::class, 'source_dataset_id');
    }

    public function fetchRun(): BelongsTo
    {
        return $this->belongsTo(SourceFetchRun::class, 'fetch_run_id');
    }
}
