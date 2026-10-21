<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\SourceCandidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Review queue for sources found by web search. Accepting a candidate records a
 * judgement about a URL; it does not create observations.
 */
class CandidateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $entity = $request->query('entity') ? Entity::findByKey((string) $request->query('entity')) : null;

        $candidates = SourceCandidate::query()
            ->with('entity')
            ->when($entity !== null, fn ($query) => $query->where('entity_id', $entity->id))
            ->when(
                $request->query('status'),
                fn ($query, $status) => $query->where('status', $status)
            )
            ->orderByDesc('id')
            ->limit((int) min($request->integer('limit', 100), 500))
            ->get();

        return response()->json([
            'data' => $candidates->map(fn (SourceCandidate $candidate): array => [
                'id' => $candidate->id,
                'entity' => $candidate->entity !== null ? [
                    'iso_alpha3' => $candidate->entity->iso_alpha3,
                    'name' => $candidate->entity->name,
                ] : null,
                'query' => $candidate->query,
                'title' => $candidate->title,
                'url' => $candidate->url,
                'host' => $candidate->host,
                'snippet' => $candidate->snippet,
                'status' => $candidate->status,
                'reviewed_at' => $candidate->reviewed_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'count' => $candidates->count(),
                'pending_total' => SourceCandidate::where('status', 'pending')->count(),
            ],
        ]);
    }

    public function update(Request $request, SourceCandidate $candidate): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:accepted,rejected,duplicate,pending'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $candidate->update([
            'status' => $validated['status'],
            'reason' => $validated['reason'] ?? null,
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'data' => ['id' => $candidate->id, 'status' => $candidate->status],
        ]);
    }
}
