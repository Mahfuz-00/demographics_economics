<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Demographics\Query\SeriesReader;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ObservationPresenter;
use App\Models\Entity;
use App\Models\Indicator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SeriesController extends Controller
{
    public function __construct(private readonly SeriesReader $reader) {}

    /**
     * Historical series for one entity and indicator.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity' => ['required', 'string'],
            'indicator' => ['required', 'string'],
            'frequency' => ['nullable', 'in:annual,quarterly,monthly,point,all'],
            'from' => ['nullable', 'integer', 'min:1500', 'max:2200'],
            'to' => ['nullable', 'integer', 'min:1500', 'max:2200'],
            'status' => ['nullable', 'string'],
            'include_projections' => ['nullable', 'boolean'],
        ]);

        $entity = $this->entity($validated['entity']);
        $indicator = $this->indicator($validated['indicator']);

        $series = $this->reader->series([$entity->id], $indicator->id, [
            'frequency' => $validated['frequency'] ?? 'annual',
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'status' => $validated['status'] ?? null,
            'include_projections' => $request->boolean('include_projections', true),
        ]);

        $points = collect($series[$entity->id] ?? [])
            ->map(fn (array $entry): array => ObservationPresenter::point($entry['primary'], $entry['alternatives']))
            ->values();

        return response()->json([
            'entity' => ObservationPresenter::entity($entity),
            'indicator' => ObservationPresenter::indicator($indicator),
            'frequency' => $validated['frequency'] ?? 'annual',
            'count' => $points->count(),
            'conflicting_periods' => $points->where('has_conflict', true)->count(),
            'missing_periods' => $points->where('is_missing', true)->count(),
            'points' => $points->all(),
        ]);
    }

    /**
     * Latest available observation per entity and indicator, with its reference period.
     */
    public function latest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entities' => ['required', 'string'],
            'indicators' => ['required', 'string'],
            'include_projections' => ['nullable', 'boolean'],
        ]);

        $entities = $this->entitiesFromList($validated['entities']);
        $indicators = $this->indicatorsFromList($validated['indicators']);

        $latest = $this->reader->latest(
            $entities->pluck('id')->all(),
            $indicators->pluck('id')->all(),
            $request->boolean('include_projections', false),
        );

        $rows = [];

        foreach ($entities as $entity) {
            foreach ($indicators as $indicator) {
                $key = $entity->id.':'.$indicator->id;
                $entry = $latest[$key] ?? null;

                $rows[] = [
                    'entity' => ObservationPresenter::entity($entity),
                    'indicator' => ObservationPresenter::indicator($indicator),
                    'latest' => $entry !== null
                        ? ObservationPresenter::point($entry['primary'], $entry['alternatives'])
                        : null,
                ];
            }
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * One indicator across several entities, aligned for charting.
     */
    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entities' => ['required', 'string'],
            'indicator' => ['required', 'string'],
            'frequency' => ['nullable', 'in:annual,quarterly,monthly,point,all'],
            'from' => ['nullable', 'integer', 'min:1500', 'max:2200'],
            'to' => ['nullable', 'integer', 'min:1500', 'max:2200'],
            'include_projections' => ['nullable', 'boolean'],
        ]);

        $entities = $this->entitiesFromList($validated['entities']);
        $indicator = $this->indicator($validated['indicator']);

        $series = $this->reader->series($entities->pluck('id')->all(), $indicator->id, [
            'frequency' => $validated['frequency'] ?? 'annual',
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'include_projections' => $request->boolean('include_projections', true),
        ]);

        $result = $entities->map(function (Entity $entity) use ($series): array {
            $points = collect($series[$entity->id] ?? [])
                ->map(fn (array $entry): array => ObservationPresenter::point($entry['primary'], $entry['alternatives']))
                ->values();

            return [
                'entity' => ObservationPresenter::entity($entity),
                'points' => $points->all(),
            ];
        })->all();

        return response()->json([
            'indicator' => ObservationPresenter::indicator($indicator),
            'frequency' => $validated['frequency'] ?? 'annual',
            'data' => $result,
        ]);
    }

    private function entity(string $key): Entity
    {
        return Entity::findByKey($key) ?? throw new NotFoundHttpException("Unknown entity [{$key}].");
    }

    private function indicator(string $code): Indicator
    {
        return Indicator::with('category')->where('code', $code)->first()
            ?? throw new NotFoundHttpException("Unknown indicator [{$code}].");
    }

    /**
     * @return Collection<int, Entity>
     */
    private function entitiesFromList(string $list)
    {
        $keys = array_values(array_filter(array_map('trim', explode(',', $list))));

        $entities = collect($keys)->map(fn (string $key): Entity => $this->entity($key));

        if ($entities->count() > 25) {
            throw new NotFoundHttpException('At most 25 entities can be compared at once.');
        }

        return $entities;
    }

    /**
     * @return Collection<int, Indicator>
     */
    private function indicatorsFromList(string $list)
    {
        $codes = array_values(array_filter(array_map('trim', explode(',', $list))));

        return collect($codes)->map(fn (string $code): Indicator => $this->indicator($code));
    }
}
