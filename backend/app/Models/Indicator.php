<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Indicator extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'supports_disaggregation' => 'boolean',
            'is_stock' => 'boolean',
            'is_per_capita' => 'boolean',
            'is_sensitive' => 'boolean',
            'is_derived' => 'boolean',
            'higher_is_better' => 'boolean',
            'decimals' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(IndicatorCategory::class, 'category_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function csvColumnMappings(): HasMany
    {
        return $this->hasMany(CsvColumnMapping::class);
    }

    public function scopeModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }

    public function scopeDemographics(Builder $query): Builder
    {
        return $query->where('module', 'demographics');
    }
}
