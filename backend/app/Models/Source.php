<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'credibility_rank' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function datasets(): HasMany
    {
        return $this->hasMany(SourceDataset::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }
}
