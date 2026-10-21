<?php

use App\Http\Controllers\Api\V1\Admin\CandidateController as AdminCandidateController;
use App\Http\Controllers\Api\V1\Admin\ConflictController as AdminConflictController;
use App\Http\Controllers\Api\V1\Admin\IngestionController as AdminIngestionController;
use App\Http\Controllers\Api\V1\Admin\ObservationController as AdminObservationController;
use App\Http\Controllers\Api\V1\Admin\QuarantineController as AdminQuarantineController;
use App\Http\Controllers\Api\V1\Admin\SourceFetchController as AdminSourceFetchController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AnalysisController;
use App\Http\Controllers\Api\V1\ConflictController;
use App\Http\Controllers\Api\V1\CoverageController;
use App\Http\Controllers\Api\V1\EntityController;
use App\Http\Controllers\Api\V1\IndicatorController;
use App\Http\Controllers\Api\V1\MatrixController;
use App\Http\Controllers\Api\V1\ObservationController;
use App\Http\Controllers\Api\V1\SeriesController;
use App\Http\Controllers\Api\V1\SourceController;
use App\Models\Observation;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public read API
|--------------------------------------------------------------------------
| Every value returned here carries its unit, status (reported / estimated /
| projected / missing), reference period and source, plus a provenance link.
*/
Route::prefix('v1')->group(function (): void {
    Route::get('health', fn () => [
        'status' => 'ok',
        'time' => now()->toIso8601String(),
        'observations' => Observation::count(),
    ]);

    Route::post('auth/token', [AuthController::class, 'token'])->middleware('throttle:10,1');

    Route::get('entities', [EntityController::class, 'index']);
    Route::get('entities/{entity}/observations', [EntityController::class, 'observations']);
    Route::get('entities/{entity}/matrix', [EntityController::class, 'matrix']);
    Route::get('entities/{entity}', [EntityController::class, 'show']);

    // Countries as rows, indicators as columns, each cell the latest known value.
    Route::get('matrix', [MatrixController::class, 'index']);

    Route::get('indicators', [IndicatorController::class, 'index']);
    Route::get('indicators/{indicator:code}', [IndicatorController::class, 'show']);

    Route::get('series', [SeriesController::class, 'index']);
    Route::get('series/latest', [SeriesController::class, 'latest']);
    Route::get('compare', [SeriesController::class, 'compare']);

    Route::get('observations/{observation}', [ObservationController::class, 'show']);
    Route::get('observations/{observation}/provenance', [ObservationController::class, 'provenance']);
    Route::get('observations/{observation}/history', [ObservationController::class, 'history']);

    Route::get('conflicts', [ConflictController::class, 'index']);
    Route::get('coverage', [CoverageController::class, 'index']);

    // How far apart sources are for one indicator, and whether two indicators move together.
    Route::get('analysis/source-gap', [AnalysisController::class, 'sourceGap']);
    Route::get('analysis/correlation', [AnalysisController::class, 'correlation']);
    Route::get('analysis/peers', [AnalysisController::class, 'peers']);
    Route::get('analysis/movers', [AnalysisController::class, 'movers']);

    Route::get('sources', [SourceController::class, 'index']);
    Route::get('sources/{source:code}', [SourceController::class, 'show']);
    Route::get('sources/{source:code}/datasets', [SourceController::class, 'datasets']);
    Route::get('sources/{source:code}/runs', [SourceController::class, 'runs']);

    /*
    |----------------------------------------------------------------------
    | Administration
    |----------------------------------------------------------------------
    | Triggering ingestions, uploading a replacement spreadsheet, and
    | reviewing quarantined rows or source conflicts.
    */
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/revoke', [AuthController::class, 'revoke']);

        Route::prefix('admin')->group(function (): void {
            Route::post('ingest/{dataset:code}', [AdminIngestionController::class, 'ingest']);
            Route::post('import/csv', [AdminIngestionController::class, 'importCsv']);

            // Per-country scrape, with a web-search fallback that only proposes sources.
            Route::post('entities/{entity}/fetch', [AdminSourceFetchController::class, 'fetch']);
            Route::get('candidates', [AdminCandidateController::class, 'index']);
            Route::patch('candidates/{candidate}', [AdminCandidateController::class, 'update']);

            // Correcting a stored value: appended as a curated revision, never overwritten.
            Route::patch('observations/{observation}', [AdminObservationController::class, 'update']);

            Route::get('batches', [AdminIngestionController::class, 'batches']);
            Route::get('batches/{batch}', [AdminIngestionController::class, 'batch']);

            Route::get('runs', [AdminIngestionController::class, 'runs']);
            Route::get('runs/{run}', [AdminIngestionController::class, 'run']);

            Route::get('quarantine', [AdminQuarantineController::class, 'index']);
            Route::patch('quarantine/{quarantine}', [AdminQuarantineController::class, 'update']);

            Route::patch('conflicts/{conflict}', [AdminConflictController::class, 'update']);
        });
    });
});
