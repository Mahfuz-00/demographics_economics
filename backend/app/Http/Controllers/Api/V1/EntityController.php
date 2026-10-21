<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Demographics\Query\SeriesReader;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ObservationPresenter;
use App\Models\Entity;
use App\Models\Indicator;
use App\Models\Observation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $entities = Entity::query()
            ->when($request->query('type'), fn ($query, $type) => $query->where('entity_type', $type))
            ->when($request->query('continent'), fn ($query, $continent) => $query->where('continent', $continent))
            ->when($request->boolean('countries_only'), fn ($query) => $query->countries())
            ->when($request->boolean('aggregates_only'), fn ($query) => $query->where('is_aggregate', true))
            ->when($request->query('q'), fn ($query, $term) => $query->where('name', 'ilike', '%'.$term.'%'))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $entities->map(fn (Entity $entity): array => ObservationPresenter::entity($entity))->all(),
            'meta' => ['count' => $entities->count()],
        ]);
    }

    public function show(Entity $entity): JsonResponse
    {
        $entity->load(['parent', 'sovereignty', 'nationalStatisticsOffices']);

        $payload = ObservationPresenter::entity($entity);
        $payload['national_statistics_offices'] = $entity->nationalStatisticsOffices->map(fn ($office): array => [
            'office_name' => $office->office_name,
            'website_url' => $office->website_url,
            'data_portal_url' => $office->data_portal_url,
            'has_api' => $office->has_api,
            'access_notes' => $office->access_notes,
        ])->all();

        return response()->json(['data' => $payload]);
    }

    /**
     * Every current value held for one entity, in long form: one row per indicator and
     * period, showing the served value and the flags that qualify it.
     */
    public function observations(Request $request, Entity $entity, SeriesReader $reader): JsonResponse
    {
        $rows = $this->observationsQuery($request, $entity);

        $grouped = [];

        foreach ($rows as $observation) {
            $grouped[$observation->indicator_id][$observation->period_key][] = $observation;
        }

        $data = [];

        foreach ($grouped as $periods) {
            ksort($periods);

            foreach ($periods as $competing) {
                $ordered = $reader->orderByPriority($competing);
                $data[] = $this->observationRow($ordered[0], count($ordered) - 1);
            }
        }

        return response()->json([
            'entity' => ObservationPresenter::entity($entity),
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'assumed_periods' => count(array_filter($data, fn (array $row): bool => $row['period_is_assumed'])),
                'curated' => count(array_filter($data, fn (array $row): bool => $row['is_curated'])),
                'rows_with_alternatives' => count(array_filter($data, fn (array $row): bool => $row['alternatives'] > 0)),
            ],
        ]);
    }

    /**
     * The same data transposed: one row per **year**, one column per indicator.
     *
     * This is the "vital statistics" shape — a year across the top of a statistical
     * table — and it is what makes a country's history readable at a glance.
     */
    public function matrix(Request $request, Entity $entity, SeriesReader $reader): JsonResponse
    {
        $rows = $this->observationsQuery($request, $entity);

        $byPeriod = [];

        foreach ($rows as $observation) {
            $byPeriod[$observation->period_key][] = $observation;
        }

        // Newest year first: the latest period is what a reader looks for.
        krsort($byPeriod);

        $data = [];
        $indicatorIds = [];

        foreach ($byPeriod as $periodKey => $competing) {
            $byIndicator = [];

            foreach ($competing as $observation) {
                $byIndicator[$observation->indicator_id][] = $observation;
            }

            $values = [];
            $year = (int) substr((string) $periodKey, 0, 4);

            foreach ($byIndicator as $indicatorId => $group) {
                $ordered = $reader->orderByPriority($group);
                $primary = $ordered[0];

                if ($primary->indicator === null) {
                    continue;
                }

                $values[$primary->indicator->code] = $this->observationRow($primary, count($ordered) - 1);
                $indicatorIds[$indicatorId] = $primary->indicator;
            }

            $data[] = [
                // PHP casts numeric-string array keys to int, so the period would
                // otherwise arrive as 2024 rather than "2024".
                'period' => (string) $periodKey,
                'year' => $year,
                'values' => $values,
            ];
        }

        $indicators = collect($indicatorIds)
            // Same column order as the country table: the spreadsheet's sequence, so the
            // year view reads across the same columns in the same places.
            ->sortBy(fn (Indicator $indicator): array => [
                $indicator->display_order ?? 1000,
                $indicator->name,
            ])
            ->values();

        return response()->json([
            'entity' => ObservationPresenter::entity($entity),
            'indicators' => $indicators->map(fn (Indicator $i): array => ObservationPresenter::indicator($i))->all(),
            'rows' => $data,
            'meta' => [
                'year_count' => count($data),
                'indicator_count' => $indicators->count(),
                'cells' => array_sum(array_map(fn (array $row): int => count($row['values']), $data)),
                'assumed_cells' => array_sum(array_map(
                    fn (array $row): int => count(array_filter($row['values'], fn (array $cell): bool => $cell['period_is_assumed'])),
                    $data
                )),
            ],
        ]);
    }

    /**
     * @return Collection<int, Observation>
     */
    private function observationsQuery(Request $request, Entity $entity): Collection
    {
        $validated = $request->validate([
            'indicator' => ['nullable', 'string'],
            'from' => ['nullable', 'integer', 'min:1500', 'max:2200'],
            'to' => ['nullable', 'integer', 'min:1500', 'max:2200'],
            'include_projections' => ['nullable', 'boolean'],
        ]);

        return Observation::query()
            ->current()
            ->where('entity_id', $entity->id)
            ->when(
                $validated['indicator'] ?? null,
                function ($query, $codes) {
                    // A comma-separated list, the same convention the series endpoints
                    // use, so a caller can ask for just the handful of columns it draws.
                    $list = array_values(array_filter(array_map('trim', explode(',', (string) $codes))));

                    $query->whereHas(
                        'indicator',
                        fn ($indicatorQuery) => $indicatorQuery->whereIn('code', $list)
                    );
                }
            )
            ->betweenYears($validated['from'] ?? null, $validated['to'] ?? null)
            ->when(
                $request->boolean('include_projections', true) === false,
                fn ($query) => $query->where('is_projection', false)
            )
            ->with(['indicator.category', 'source'])
            ->orderBy('indicator_id')
            ->orderBy('period_year')
            ->limit(20000)
            ->get();
    }

    private function observationRow(Observation $observation, int $alternatives): array
    {
        return [
            'observation_uuid' => $observation->uuid,
            'indicator' => $observation->indicator !== null ? [
                'code' => $observation->indicator->code,
                'name' => $observation->indicator->name,
                'unit' => $observation->indicator->unit,
                'decimals' => $observation->indicator->decimals,
                'module' => $observation->indicator->module,
                'category' => $observation->indicator->category?->name,
            ] : null,
            'period' => $observation->periodLabel(),
            'period_year' => $observation->period_year,
            'period_is_assumed' => $observation->period_is_assumed,
            'value' => $observation->value,
            'status' => $observation->status,
            'is_estimate' => $observation->is_estimate,
            'is_projection' => $observation->is_projection,
            'is_missing' => $observation->isMissing(),
            'is_curated' => $observation->is_curated,
            'is_derived' => $observation->is_derived,
            'missing_reason' => $observation->missing_reason,
            'reference_note' => $observation->reference_note,
            'alternatives' => $alternatives,
            'source' => [
                'code' => $observation->source?->code,
                'publisher_short' => $observation->source?->publisher_short,
                'credibility_rank' => $observation->source?->credibility_rank,
            ],
            'retrieved_at' => $observation->retrieved_at?->toIso8601String(),
        ];
    }
}
