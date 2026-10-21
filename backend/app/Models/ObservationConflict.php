<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservationConflict extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'value_delta' => 'float',
            'pct_delta' => 'float',
            'resolved_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    public function primaryObservation(): BelongsTo
    {
        return $this->belongsTo(Observation::class, 'primary_observation_id');
    }

    public function conflictingObservation(): BelongsTo
    {
        return $this->belongsTo(Observation::class, 'conflicting_observation_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('resolution_status', 'open');
    }
}
