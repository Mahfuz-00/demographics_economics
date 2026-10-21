<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceFetchRun extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'http_status' => 'integer',
            'bytes' => 'integer',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(SourceDataset::class, 'source_dataset_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(SourceSnapshot::class, 'fetch_run_id');
    }

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
