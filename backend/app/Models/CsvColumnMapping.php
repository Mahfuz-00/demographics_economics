<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsvColumnMapping extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sub_table_order' => 'integer',
            'source_column_index' => 'integer',
            'year_source_column_index' => 'integer',
        ];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    public function scopeSubTable(Builder $query, string $subTable): Builder
    {
        return $query->where('sub_table', $subTable);
    }
}
