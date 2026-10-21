<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ObservationPresenter;
use App\Http\Presenters\SourcePresenter;
use App\Models\Source;
use Illuminate\Http\JsonResponse;

class SourceController extends Controller
{
    public function index(): JsonResponse
    {
        $sources = Source::query()
            ->withCount('datasets')
            ->orderBy('credibility_rank')
            ->orderBy('publisher')
            ->get();

        return response()->json([
            'data' => $sources->map(fn (Source $source): array => array_merge(
                ObservationPresenter::source($source),
                ['dataset_count' => $source->datasets_count]
            ))->all(),
            'meta' => ['count' => $sources->count()],
        ]);
    }

    public function show(Source $source): JsonResponse
    {
        $source->load('datasets');

        return response()->json([
            'data' => array_merge(ObservationPresenter::source($source), [
                'license_url' => $source->license_url,
                'terms_note' => $source->terms_note,
                'is_active' => $source->is_active,
                'datasets' => SourcePresenter::datasetCollection($source->datasets),
            ]),
        ]);
    }

    public function datasets(Source $source): JsonResponse
    {
        $source->load('datasets.source');

        return response()->json([
            'source' => ObservationPresenter::source($source),
            'data' => SourcePresenter::datasetCollection($source->datasets),
        ]);
    }

    public function runs(Source $source): JsonResponse
    {
        $runs = $source->datasets()
            ->with(['fetchRuns' => fn ($query) => $query->orderByDesc('started_at')->limit(25), 'fetchRuns.dataset'])
            ->get()
            ->flatMap(fn ($dataset) => $dataset->fetchRuns)
            ->sortByDesc('started_at')
            ->values();

        return response()->json([
            'source' => ObservationPresenter::source($source),
            'data' => $runs->map(fn ($run): array => SourcePresenter::run($run))->all(),
        ]);
    }
}
