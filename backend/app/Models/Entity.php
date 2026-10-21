<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
    use HasFactory;
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_aggregate' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(EntityNameAlias::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_entity_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_entity_id');
    }

    public function sovereignty(): BelongsTo
    {
        return $this->belongsTo(self::class, 'sovereignty_entity_id');
    }

    public function dependentTerritories(): HasMany
    {
        return $this->hasMany(self::class, 'sovereignty_entity_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function nationalStatisticsOffices(): HasMany
    {
        return $this->hasMany(NationalStatisticsOffice::class);
    }

    public function scopeCountries(Builder $query): Builder
    {
        return $query->whereIn('entity_type', ['country', 'dependency', 'territory']);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('entity_type', $type);
    }

    public function isCountry(): bool
    {
        return in_array($this->entity_type, ['country', 'dependency', 'territory'], true);
    }

    /**
     * Route binding accepts any identifier a client might hold — ISO code, slug or
     * UUID — rather than only the public UUID.
     */
    public function resolveRouteBinding($value, $field = null): ?static
    {
        return static::findByKey((string) $value);
    }

    /**
     * Looks an entity up by any of the identifiers clients reasonably hold: ISO code,
     * slug, or public UUID. The UUID branch is guarded because comparing a non-UUID
     * string against a uuid column is an error in PostgreSQL, not just a miss.
     */
    public static function findByKey(string $key): ?self
    {
        $key = trim($key);
        $upper = strtoupper($key);
        $looksLikeUuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key);

        return static::query()
            ->where(function ($query) use ($key, $upper, $looksLikeUuid): void {
                $query->where('iso_alpha2', $upper)
                    ->orWhere('iso_alpha3', $upper)
                    ->orWhere('slug', $key);

                if ($looksLikeUuid) {
                    $query->orWhere('uuid', $key);
                }
            })
            ->first();
    }
}
