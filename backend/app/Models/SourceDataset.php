<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceDataset extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function fetchRuns(): HasMany
    {
        return $this->hasMany(SourceFetchRun::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(SourceSnapshot::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function credential(): ?string
    {
        if (! $this->credential_env_var) {
            return null;
        }

        return env($this->credential_env_var);
    }
}
