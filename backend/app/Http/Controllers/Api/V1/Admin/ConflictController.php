<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ObservationConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conflict review. Resolution records a decision; it never deletes the losing value.
 */
class ConflictController extends Controller
{
    private const STATUSES = [
        'open', 'auto_resolved_by_priority', 'reviewed_kept_primary',
        'reviewed_changed_primary', 'dismissed',
    ];

    public function update(Request $request, ObservationConflict $conflict): JsonResponse
    {
        $validated = $request->validate([
            'resolution_status' => ['required', 'in:'.implode(',', self::STATUSES)],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $conflict->update([
            'resolution_status' => $validated['resolution_status'],
            'resolution_note' => $validated['resolution_note'] ?? null,
            'resolved_by' => $request->user()?->id,
            'resolved_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id' => $conflict->id,
                'resolution_status' => $conflict->resolution_status,
                'resolved_at' => $conflict->resolved_at?->toIso8601String(),
            ],
        ]);
    }
}
