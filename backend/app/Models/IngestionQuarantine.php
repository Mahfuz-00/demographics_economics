<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestionQuarantine extends Model
{
    use HasFactory;

    protected $table = 'ingestion_quarantine';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'raw_row' => 'array',
            'resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IngestionBatch::class, 'batch_id');
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(SourceDataset::class, 'source_dataset_id');
    }

    public function resolvedObservation(): BelongsTo
    {
        return $this->belongsTo(Observation::class, 'resolved_observation_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('resolved', false);
    }
}
