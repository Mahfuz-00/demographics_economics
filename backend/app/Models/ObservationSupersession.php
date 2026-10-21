<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservationSupersession extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $primaryKey = 'superseded_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function superseded(): BelongsTo
    {
        return $this->belongsTo(Observation::class, 'superseded_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(Observation::class, 'superseded_by_id');
    }
}
