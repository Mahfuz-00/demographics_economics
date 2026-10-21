<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Demographics\Curation\ObservationCurator;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ObservationPresenter;
use App\Models\Observation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Correcting a stored value. The correction is appended as a curated revision; the
 * value it replaces stays on the record.
 */
class ObservationController extends Controller
{
    public function __construct(private readonly ObservationCurator $curator) {}

    public function update(Request $request, Observation $observation): JsonResponse
    {
        $validated = $request->validate([
            'value' => ['nullable', 'numeric'],
            'period_year' => ['nullable', 'integer', 'min:1500', 'max:2200'],
            'status' => ['nullable', 'in:reported,preliminary,revised,estimated,projected,modelled,manual_entry,missing,not_applicable'],
            'missing_reason' => ['nullable', 'in:not_collected,not_published,suppressed,not_applicable,pending,unknown'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $curated = $this->curator->curate($observation, $validated, $request->user()?->id);
        $curated->load(['entity', 'indicator', 'source', 'sourceDataset', 'sourceSnapshot']);

        return response()->json([
            'data' => ObservationPresenter::full($curated),
            'superseded_observation_uuid' => $observation->uuid,
        ]);
    }
}
