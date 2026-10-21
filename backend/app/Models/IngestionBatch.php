<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngestionBatch extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'report' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class, 'import_batch_id');
    }

    public function quarantined(): HasMany
    {
        return $this->hasMany(IngestionQuarantine::class, 'batch_id');
    }
}
