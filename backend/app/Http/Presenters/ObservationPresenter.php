<?php

namespace App\Http\Presenters;

use App\Models\Entity;
use App\Models\Indicator;
use App\Models\Observation;
use App\Models\Source;

/**
 * Shapes observations for the API so that every value carries enough context to be
 * traced: what it is, how confident we are in it, which source it came from, and
 * whether another source disagrees.
 */
class ObservationPresenter
{
    /**
     * @param  array<int, Observation>  $alternatives
     */
    public static function point(Observation $primary, array $alternatives = []): array
    {
        $disagreeing = array_values(array_filter(
            $alternatives,
            fn (Observation $other): bool => ! self::valuesAgree($primary->value, $other->value)
        ));

        return [
            'period' => $primary->periodLabel(),
            'period_key' => $primary->period_key,
            'period_type' => $primary->period_type,
            'year' => $primary->period_year,
            'quarter' => $primary->period_quarter,
            'month' => $primary->period_month,
            'value' => $primary->value,
            'value_text' => $primary->value_text,
            'unit' => $primary->unit,
            'status' => $primary->status,
            'is_estimate' => $primary->is_estimate,
            'is_projection' => $primary->is_projection,
            'is_missing' => $primary->isMissing(),
            'missing_reason' => $primary->missing_reason,
            'period_is_assumed' => $primary->period_is_assumed,
            'is_curated' => $primary->is_curated,
            'is_derived' => $primary->is_derived,
            'raw_value' => $primary->raw_value,
            'reference_note' => $primary->reference_note,
            'revision' => $primary->revision,
            'retrieved_at' => $primary->retrieved_at?->toIso8601String(),
            'observation_uuid' => $primary->uuid,
            'source' => self::source($primary->source, brief: true),
            'provenance_url' => '/api/v1/observations/'.$primary->uuid.'/provenance',
            // True only when another source holds a different number for this period.
            'has_conflict' => $disagreeing !== [],
            'other_sources' => array_map(fn (Observation $other): array => [
                'value' => $other->value,
                'status' => $other->status,
                'agrees' => self::valuesAgree($primary->value, $other->value),
                'observation_uuid' => $other->uuid,
                'source' => self::source($other->source, brief: true),
                'provenance_url' => '/api/v1/observations/'.$other->uuid.'/provenance',
            ], $alternatives),
        ];
    }

    private static function valuesAgree(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return abs((float) $a - (float) $b) <= (float) config('demographics.conflicts.absolute_epsilon', 0.000001);
    }

    public static function full(Observation $observation): array
    {
        return array_merge(self::point($observation), [
            'entity' => $observation->entity !== null ? [
                'uuid' => $observation->entity->uuid,
                'iso_alpha2' => $observation->entity->iso_alpha2,
                'iso_alpha3' => $observation->entity->iso_alpha3,
                'name' => $observation->entity->name,
                'entity_type' => $observation->entity->entity_type,
            ] : null,
            'indicator' => $observation->indicator !== null ? [
                'code' => $observation->indicator->code,
                'name' => $observation->indicator->name,
                'value_kind' => $observation->indicator->value_kind,
                'module' => $observation->indicator->module,
            ] : null,
            'period_range' => [
                'start' => $observation->period_start?->toDateString(),
                'end' => $observation->period_end?->toDateString(),
            ],
            'source_record_ref' => $observation->source_record_ref,
            'published_date' => $observation->published_date?->toDateString(),
            'retrieved_at' => $observation->retrieved_at?->toIso8601String(),
            'snapshot' => $observation->sourceSnapshot !== null ? [
                'content_hash' => $observation->sourceSnapshot->content_hash,
                'url' => $observation->sourceSnapshot->url,
                'fetched_at' => $observation->sourceSnapshot->fetched_at?->toIso8601String(),
                'byte_size' => $observation->sourceSnapshot->byte_size,
                'storage_path' => $observation->sourceSnapshot->storage_path,
            ] : null,
            'notes' => $observation->notes,
            'quality_score' => $observation->quality_score,
            'import_batch_id' => $observation->import_batch_id,
        ]);
    }

    public static function source(?Source $source, bool $brief = false): ?array
    {
        if ($source === null) {
            return null;
        }

        if ($brief) {
            return [
                'code' => $source->code,
                'publisher_short' => $source->publisher_short,
                'source_type' => $source->source_type,
                'credibility_rank' => $source->credibility_rank,
            ];
        }

        return [
            'code' => $source->code,
            'publisher' => $source->publisher,
            'publisher_short' => $source->publisher_short,
            'source_type' => $source->source_type,
            'credibility_rank' => $source->credibility_rank,
            'homepage_url' => $source->homepage_url,
            'license' => $source->license,
        ];
    }

    public static function entity(Entity $entity): array
    {
        return [
            'uuid' => $entity->uuid,
            'iso_alpha2' => $entity->iso_alpha2,
            'iso_alpha3' => $entity->iso_alpha3,
            'iso_numeric' => $entity->iso_numeric,
            'name' => $entity->name,
            'slug' => $entity->slug,
            'entity_type' => $entity->entity_type,
            'is_aggregate' => $entity->is_aggregate,
            'continent' => $entity->continent,
            'subregion' => $entity->subregion,
            'csv_label' => $entity->csv_label,
            'sovereignty' => $entity->relationLoaded('sovereignty') && $entity->sovereignty !== null
                ? ['iso_alpha3' => $entity->sovereignty->iso_alpha3, 'name' => $entity->sovereignty->name]
                : null,
            'parent' => $entity->relationLoaded('parent') && $entity->parent !== null
                ? ['slug' => $entity->parent->slug, 'name' => $entity->parent->name]
                : null,
        ];
    }

    public static function indicator(Indicator $indicator): array
    {
        return [
            'code' => $indicator->code,
            'name' => $indicator->name,
            'description' => $indicator->description,
            'definition' => $indicator->definition,
            'module' => $indicator->module,
            'unit' => $indicator->unit,
            'value_kind' => $indicator->value_kind,
            'decimals' => $indicator->decimals,
            'built_in_frequency' => $indicator->built_in_frequency,
            'supports_disaggregation' => $indicator->supports_disaggregation,
            'is_stock' => $indicator->is_stock,
            'is_per_capita' => $indicator->is_per_capita,
            'is_sensitive' => $indicator->is_sensitive,
            'aggregation_method' => $indicator->aggregation_method,
            'category' => $indicator->relationLoaded('category') && $indicator->category !== null
                ? ['code' => $indicator->category->code, 'name' => $indicator->category->name]
                : null,
        ];
    }
}
