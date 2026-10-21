<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ObservationPresenter;
use App\Models\Entity;
use App\Models\Indicator;
use App\Models\Observation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Answers "what do we actually have, and how stale is it", per entity and indicator.
 *
 * This is where absent data is made explicit: instead of writing placeholder rows
 * for every year a source omits, the coverage report states the span held, the
 * number of periods recorded as missing, and when the series was last refreshed.
 */
class CoverageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity' => ['nullable', 'string'],
            'module' => ['nullable', 'string'],
        ]);

        $entity = isset($validated['entity']) ? Entity::findByKey($validated['entity']) : null;

        $rows = Observation::query()
            ->current()
            ->when($entity !== null, fn ($query) => $query->where('entity_id', $entity->id))
            ->when(
                $validated['module'] ?? null,
                fn ($query, $module) => $query->whereHas(
                    'indicator',
                    fn ($indicatorQuery) => $indicatorQuery->where('module', $module)
                )
            )
            ->selectRaw('indicator_id')
            ->selectRaw('count(*) as observations')
            ->selectRaw('min(period_year) as first_year')
            ->selectRaw('max(period_year) as last_year')
            ->selectRaw("count(*) filter (where status = 'missing') as missing_periods")
            ->selectRaw('count(*) filter (where is_projection) as projected_periods')
            ->selectRaw('count(*) filter (where is_estimate) as estimated_periods')
            ->selectRaw('count(distinct source_id) as source_count')
            ->selectRaw('max(retrieved_at) as last_retrieved_at')
            ->groupBy('indicator_id')
            ->with('indicator')
            ->get();

        $indicators = Indicator::query()->get()->keyBy('id');

        $data = $rows->map(function ($row) use ($indicators): array {
            $indicator = $indicators->get($row->indicator_id);
            $lastRetrieved = $row->last_retrieved_at !== null ? Carbon::parse($row->last_retrieved_at) : null;

            return [
                'indicator' => $indicator !== null ? [
                    'code' => $indicator->code,
                    'name' => $indicator->name,
                    'unit' => $indicator->unit,
                    'module' => $indicator->module,
                ] : null,
                'observations' => (int) $row->observations,
                'span' => ['from' => (int) $row->first_year, 'to' => (int) $row->last_year],
                'missing_periods' => (int) $row->missing_periods,
                'projected_periods' => (int) $row->projected_periods,
                'estimated_periods' => (int) $row->estimated_periods,
                'source_count' => (int) $row->source_count,
                'last_retrieved_at' => $lastRetrieved?->toIso8601String(),
                'days_since_retrieval' => $lastRetrieved?->diffInDays(now()),
            ];
        })->sortBy('indicator.code')->values();

        return response()->json([
            'entity' => $entity !== null ? ObservationPresenter::entity($entity) : null,
            'data' => $data->all(),
            'meta' => ['indicator_count' => $data->count()],
        ]);
    }
}
