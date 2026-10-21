<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Demographics\Import\CsvDemographicImporter;
use App\Domain\Demographics\Ingestion\IngestionPipeline;
use App\Http\Controllers\Controller;
use App\Http\Presenters\SourcePresenter;
use App\Jobs\FetchSourceDatasetJob;
use App\Models\IngestionBatch;
use App\Models\SourceDataset;
use App\Models\SourceFetchRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngestionController extends Controller
{
    public function __construct(
        private readonly IngestionPipeline $pipeline,
        private readonly CsvDemographicImporter $importer,
    ) {}

    /**
     * Kicks off a fetch for one dataset, inline or queued.
     */
    public function ingest(Request $request, SourceDataset $dataset): JsonResponse
    {
        $validated = $request->validate([
            'queued' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('queued')) {
            FetchSourceDatasetJob::dispatch($dataset->id, 'api', $request->user()?->id);

            return response()->json(['queued' => true, 'dataset' => $dataset->code], 202);
        }

        $run = $this->pipeline->run($dataset, 'api', $request->user()?->id);

        return response()->json(['data' => SourcePresenter::run($run)]);
    }

    /**
     * Uploads a replacement for the hand-maintained spreadsheet.
     */
    public function importCsv(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
            'dataset' => ['nullable', 'string', 'exists:source_datasets,code'],
            'record_missing' => ['nullable', 'boolean'],
        ]);

        $path = $validated['file']->getRealPath();

        $batch = $this->importer->import(
            $path,
            datasetCode: $validated['dataset'] ?? null,
            recordMissing: $request->boolean('record_missing', true),
            userId: $request->user()?->id,
        );

        return response()->json(['data' => $batch->only([
            'id', 'status', 'rows_seen', 'observations_created', 'observations_unchanged',
            'observations_superseded', 'quarantined', 'conflicts', 'report',
        ])]);
    }

    public function batches(Request $request): JsonResponse
    {
        $batches = IngestionBatch::query()
            ->with('dataset')
            ->orderByDesc('id')
            ->limit((int) min($request->integer('limit', 50), 200))
            ->get()
            ->map(fn (IngestionBatch $batch): array => [
                'id' => $batch->id,
                'kind' => $batch->kind,
                'status' => $batch->status,
                'file_name' => $batch->file_name,
                'dataset' => $batch->dataset?->code,
                'rows_seen' => $batch->rows_seen,
                'observations_created' => $batch->observations_created,
                'observations_unchanged' => $batch->observations_unchanged,
                'observations_superseded' => $batch->observations_superseded,
                'quarantined' => $batch->quarantined,
                'conflicts' => $batch->conflicts,
                'started_at' => $batch->started_at?->toIso8601String(),
                'finished_at' => $batch->finished_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $batches]);
    }

    public function batch(IngestionBatch $batch): JsonResponse
    {
        $batch->load(['dataset', 'fetchRun', 'quarantined']);

        return response()->json([
            'data' => [
                'id' => $batch->id,
                'kind' => $batch->kind,
                'status' => $batch->status,
                'file_name' => $batch->file_name,
                'report' => $batch->report,
                'dataset' => $batch->dataset?->code,
                'fetch_run' => $batch->fetchRun !== null ? SourcePresenter::run($batch->fetchRun) : null,
                'quarantine' => $batch->quarantined->map(fn ($row): array => [
                    'id' => $row->id,
                    'sub_table' => $row->sub_table,
                    'row_number' => $row->row_number,
                    'reason_code' => $row->reason_code,
                    'reason_detail' => $row->reason_detail,
                    'resolved' => $row->resolved,
                ])->all(),
            ],
        ]);
    }

    public function runs(Request $request): JsonResponse
    {
        $runs = SourceFetchRun::query()
            ->with('dataset')
            ->orderByDesc('started_at')
            ->limit((int) min($request->integer('limit', 50), 200))
            ->get();

        return response()->json(['data' => $runs->map(fn ($run): array => SourcePresenter::run($run))]);
    }

    public function run(SourceFetchRun $run): JsonResponse
    {
        $run->load('dataset');

        return response()->json(['data' => SourcePresenter::run($run)]);
    }
}
