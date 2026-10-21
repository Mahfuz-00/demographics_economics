<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\IngestionQuarantine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The review queue for data the importer refused to guess at: unresolvable entity
 * names, unreadable cells, spreadsheet error tokens, and values with no stated
 * reference period. Nothing here was silently dropped or coerced.
 */
class QuarantineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = IngestionQuarantine::query()
            ->with(['batch', 'dataset'])
            ->when(
                $request->has('resolved'),
                fn ($query) => $query->where('resolved', $request->boolean('resolved')),
                fn ($query) => $query->where('resolved', false)
            )
            ->when($request->query('reason_code'), fn ($query, $reason) => $query->where('reason_code', $reason))
            ->when($request->query('sub_table'), fn ($query, $subTable) => $query->where('sub_table', $subTable))
            ->orderByDesc('id')
            ->limit((int) min($request->integer('limit', 100), 500))
            ->get();

        return response()->json([
            'data' => $rows->map(fn (IngestionQuarantine $row): array => [
                'id' => $row->id,
                'batch_id' => $row->batch_id,
                'dataset' => $row->dataset?->code,
                'sub_table' => $row->sub_table,
                'row_number' => $row->row_number,
                'reason_code' => $row->reason_code,
                'reason_detail' => $row->reason_detail,
                'raw_row' => $row->raw_row,
                'resolved' => $row->resolved,
                'resolved_at' => $row->resolved_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'count' => $rows->count(),
                'unresolved_total' => IngestionQuarantine::where('resolved', false)->count(),
                'by_reason' => IngestionQuarantine::query()
                    ->where('resolved', false)
                    ->selectRaw('reason_code, count(*) as total')
                    ->groupBy('reason_code')
                    ->pluck('total', 'reason_code')
                    ->all(),
            ],
        ]);
    }

    public function update(Request $request, IngestionQuarantine $quarantine): JsonResponse
    {
        $validated = $request->validate([
            'resolved' => ['required', 'boolean'],
            'resolved_observation_id' => ['nullable', 'integer', 'exists:observations,id'],
            'reason_detail' => ['nullable', 'string'],
        ]);

        $quarantine->update([
            'resolved' => $validated['resolved'],
            'resolved_observation_id' => $validated['resolved_observation_id'] ?? $quarantine->resolved_observation_id,
            'reason_detail' => $validated['reason_detail'] ?? $quarantine->reason_detail,
            'resolved_by' => $request->user()?->id,
            'resolved_at' => $validated['resolved'] ? now() : null,
        ]);

        return response()->json(['data' => ['id' => $quarantine->id, 'resolved' => $quarantine->resolved]]);
    }
}
