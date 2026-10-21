<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ObservationPresenter;
use App\Models\Entity;
use App\Models\ObservationConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConflictController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $entity = $request->query('entity') ? Entity::findByKey((string) $request->query('entity')) : null;

        $conflicts = ObservationConflict::query()
            ->with([
                'entity', 'indicator',
                'primaryObservation.source', 'conflictingObservation.source',
            ])
            ->when($entity !== null, fn ($query) => $query->where('entity_id', $entity->id))
            ->when($request->query('indicator'), fn ($query, $code) => $query->whereHas(
                'indicator',
                fn ($indicatorQuery) => $indicatorQuery->where('code', $code)
            ))
            ->when($request->query('significance'), fn ($query, $significance) => $query->where('significance', $significance))
            ->when($request->query('resolution_status'), fn ($query, $status) => $query->where('resolution_status', $status))
            ->orderByRaw("case significance when 'major' then 1 when 'notable' then 2 else 3 end")
            ->orderByDesc('pct_delta')
            ->limit((int) min($request->integer('limit', 100), 500))
            ->get();

        return response()->json([
            'data' => $conflicts->map(fn (ObservationConflict $conflict): array => [
                'id' => $conflict->id,
                'entity' => $conflict->entity !== null ? [
                    'iso_alpha3' => $conflict->entity->iso_alpha3,
                    'name' => $conflict->entity->name,
                ] : null,
                'indicator' => $conflict->indicator !== null ? [
                    'code' => $conflict->indicator->code,
                    'name' => $conflict->indicator->name,
                ] : null,
                'period' => $conflict->period_key,
                'period_type' => $conflict->period_type,
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
                'resolution_note' => $conflict->resolution_note,
                'resolved_at' => $conflict->resolved_at?->toIso8601String(),
            ])->all(),
            'meta' => ['count' => $conflicts->count()],
        ]);
    }
}
