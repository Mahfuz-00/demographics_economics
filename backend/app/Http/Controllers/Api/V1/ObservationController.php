<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ObservationPresenter;
use App\Http\Presenters\SourcePresenter;
use App\Models\Observation;
use App\Models\ObservationConflict;
use Illuminate\Http\JsonResponse;

class ObservationController extends Controller
{
    private const RELATIONS = [
        'entity', 'indicator', 'source', 'sourceDataset', 'sourceSnapshot', 'importBatch',
    ];

    public function show(Observation $observation): JsonResponse
    {
        $observation->load(self::RELATIONS);

        return response()->json(['data' => ObservationPresenter::full($observation)]);
    }

    /**
     * Everything needed to answer "where did this number come from": the source
     * organisation, the exact payload it was parsed from, every value that was
     * replaced before it, and any concurrent disagreement from another source.
     */
    public function provenance(Observation $observation): JsonResponse
    {
        $observation->load(array_merge(self::RELATIONS, ['supersession', 'supersedes']));

        $supersededBy = $observation->supersession?->supersededBy;
        $superseded = $observation->supersedes?->superseded;

        $conflicts = $observation->entity !== null ? ObservationConflict::query()
            ->where('entity_id', $observation->entity_id)
            ->where('indicator_id', $observation->indicator_id)
            ->where('period_key', $observation->period_key)
            ->with(['primaryObservation.source', 'conflictingObservation.source'])
            ->get() : collect();

        return response()->json([
            'data' => ObservationPresenter::full($observation),
            'snapshot' => $observation->sourceSnapshot !== null
                ? SourcePresenter::snapshot($observation->sourceSnapshot)
                : null,
            'revisions' => [
                'this_is_current' => $observation->isCurrent(),
                'superseded_by' => $supersededBy !== null ? [
                    'observation_uuid' => $supersededBy->uuid,
                    'value' => $supersededBy->value,
                    'revision' => $supersededBy->revision,
                    'retrieved_at' => $supersededBy->retrieved_at?->toIso8601String(),
                ] : null,
                'replaced' => $superseded !== null ? [
                    'observation_uuid' => $superseded->uuid,
                    'value' => $superseded->value,
                    'revision' => $superseded->revision,
                    'retrieved_at' => $superseded->retrieved_at?->toIso8601String(),
                ] : null,
            ],
            'conflicts' => $conflicts->map(fn ($conflict): array => [
                'id' => $conflict->id,
                'significance' => $conflict->significance,
                'resolution_status' => $conflict->resolution_status,
                'value_delta' => $conflict->value_delta,
                'pct_delta' => $conflict->pct_delta,
                'primary' => [
                    'observation_uuid' => $conflict->primaryObservation->uuid,
                    'value' => $conflict->primaryObservation->value,
                    'source' => ObservationPresenter::source($conflict->primaryObservation->source),
                ],
                'conflicting' => [
                    'observation_uuid' => $conflict->conflictingObservation->uuid,
                    'value' => $conflict->conflictingObservation->value,
                    'source' => ObservationPresenter::source($conflict->conflictingObservation->source),
                ],
            ])->all(),
        ]);
    }

    /**
     * Full revision history for the series point this observation belongs to.
     * Superseded rows are always still present — nothing is overwritten.
     */
    public function history(Observation $observation): JsonResponse
    {
        $observations = Observation::query()
            ->where('entity_id', $observation->entity_id)
            ->where('indicator_id', $observation->indicator_id)
            ->where('period_key', $observation->period_key)
            ->with(['source', 'supersession'])
            ->orderBy('revision')
            ->get();

        return response()->json([
            'entity_id' => $observation->entity_id,
            'indicator_id' => $observation->indicator_id,
            'period_key' => $observation->period_key,
            'count' => $observations->count(),
            'data' => $observations->map(fn (Observation $row): array => [
                'observation_uuid' => $row->uuid,
                'value' => $row->value,
                'status' => $row->status,
                'raw_value' => $row->raw_value,
                'revision' => $row->revision,
                'is_current' => $row->supersession === null,
                'retrieved_at' => $row->retrieved_at?->toIso8601String(),
                'source' => ObservationPresenter::source($row->source),
            ])->all(),
        ]);
    }
}
