<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ObservationPresenter;
use App\Models\Indicator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IndicatorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $indicators = Indicator::query()
            ->with('category')
            ->when(
                $request->query('module'),
                fn ($query, $module) => $query->where('indicators.module', $module)
            )
            ->when($request->query('category'), fn ($query, $category) => $query->whereHas(
                'category',
                fn ($categoryQuery) => $categoryQuery->where('code', $category)
            ))
            ->when($request->boolean('without_sensitive'), fn ($query) => $query->where('indicators.is_sensitive', false))
            ->join('indicator_categories', 'indicator_categories.id', '=', 'indicators.category_id')
            ->orderBy('indicator_categories.sort_order')
            ->orderBy('indicators.name')
            ->select('indicators.*')
            ->get();

        return response()->json([
            'data' => $indicators->map(fn (Indicator $indicator): array => ObservationPresenter::indicator($indicator))->all(),
            'meta' => ['count' => $indicators->count()],
        ]);
    }

    public function show(Indicator $indicator): JsonResponse
    {
        $indicator->load('category');

        return response()->json(['data' => ObservationPresenter::indicator($indicator)]);
    }
}
