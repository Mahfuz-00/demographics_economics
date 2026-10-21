<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * An immutable observation of a single indicator, for a single entity, in a single
 * period, from a single source.
 *
 * Rows are never updated: a corrected or updated value is inserted as a new revision
 * and linked from the row it replaces via `observation_supersessions`. Queries that
 * want the currently effective value use `scopeCurrent()`, which selects observations
 * that no supersession row names as superseded.
 */
class Observation extends Model
{
    use HasFactory;
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_quarter' => 'integer',
            'period_month' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'value' => 'float',
            'is_estimate' => 'boolean',
            'is_projection' => 'boolean',
            'is_actual' => 'boolean',
            'period_is_assumed' => 'boolean',
            'is_curated' => 'boolean',
            'is_derived' => 'boolean',
            'published_date' => 'date',
            'retrieved_at' => 'datetime',
            'revision' => 'integer',
            'quality_score' => 'integer',
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

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function sourceDataset(): BelongsTo
    {
        return $this->belongsTo(SourceDataset::class, 'source_dataset_id');
    }

    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(SourceSnapshot::class, 'source_snapshot_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(IngestionBatch::class, 'import_batch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The record that says "a newer value replaced this row", if any. */
    public function supersession(): HasOne
    {
        return $this->hasOne(ObservationSupersession::class, 'superseded_id');
    }

    /** The record that says "this row replaced an older one", if any. */
    public function supersedes(): HasOne
    {
        return $this->hasOne(ObservationSupersession::class, 'superseded_by_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNotExists(function ($sub): void {
            $sub->select(DB::raw(1))
                ->from('observation_supersessions')
                ->whereColumn('observation_supersessions.superseded_id', 'observations.id');
        });
    }

    public function scopeForSeries(Builder $query, int $entityId, int $indicatorId): Builder
    {
        return $query->where('entity_id', $entityId)->where('indicator_id', $indicatorId);
    }

    public function scopeAnnual(Builder $query): Builder
    {
        return $query->where('period_type', 'annual');
    }

    public function scopeFrequency(Builder $query, string $frequency): Builder
    {
        return $query->where('period_type', $frequency);
    }

    public function scopeBetweenYears(Builder $query, ?int $from, ?int $to): Builder
    {
        return $query
            ->when($from !== null, fn (Builder $q) => $q->where('period_year', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('period_year', '<=', $to));
    }

    public function scopeOnlyActuals(Builder $query): Builder
    {
        return $query->where('status', '!=', 'projected')->where('is_projection', false);
    }

    public function isMissing(): bool
    {
        return $this->status === 'missing' || $this->value === null;
    }

    public function isCurrent(): bool
    {
        if ($this->relationLoaded('supersession')) {
            return $this->supersession === null;
        }

        return ! $this->supersession()->exists();
    }

    public function periodLabel(): string
    {
        return match ($this->period_type) {
            'quarterly' => $this->period_year.' Q'.$this->period_quarter,
            'monthly' => sprintf('%d-%02d', $this->period_year, (int) $this->period_month),
            default => (string) $this->period_year,
        };
    }

    /**
     * Canonical key for the period an observation covers: `2024`, `2024Q3`, `2024-07`.
     * Non-null by design so the series/revision uniqueness constraint is meaningful.
     */
    public static function makePeriodKey(string $periodType, int $year, ?int $quarter = null, ?int $month = null): string
    {
        return match ($periodType) {
            'quarterly' => $year.'Q'.($quarter ?? 1),
            'monthly' => sprintf('%d-%02d', $year, $month ?? 1),
            default => (string) $year,
        };
    }

    public static function periodBounds(string $periodType, int $year, ?int $quarter = null, ?int $month = null): array
    {
        switch ($periodType) {
            case 'quarterly':
                $startMonth = (($quarter ?? 1) - 1) * 3 + 1;

                return [
                    sprintf('%d-%02d-01', $year, $startMonth),
                    date('Y-m-t', mktime(0, 0, 0, $startMonth + 2, 1, $year)),
                ];
            case 'monthly':
                $month ??= 1;

                return [
                    sprintf('%d-%02d-01', $year, $month),
                    date('Y-m-t', mktime(0, 0, 0, $month, 1, $year)),
                ];
            default:
                return [$year.'-01-01', $year.'-12-31'];
        }
    }

    /**
     * Stable fingerprint of a recorded fact. Two fetches that describe the same
     * value for the same series, period and source produce the same hash, which is
     * what lets the writer skip re-inserting unchanged data.
     */
    public static function contentHash(array $parts): string
    {
        return hash('sha256', implode('|', [
            $parts['entity_id'] ?? '',
            $parts['indicator_id'] ?? '',
            $parts['period_type'] ?? '',
            $parts['period_key'] ?? '',
            $parts['source_id'] ?? '',
            $parts['status'] ?? '',
            self::normalizeNumeric($parts['value'] ?? null),
            $parts['value_text'] ?? '',
            $parts['missing_reason'] ?? '',
        ]));
    }

    private static function normalizeNumeric(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $formatted = number_format((float) $value, 10, '.', '');

        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }
}
