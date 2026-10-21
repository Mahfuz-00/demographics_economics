<?php

namespace App\Domain\Demographics\Curation;

use App\Models\IngestionBatch;
use App\Models\Observation;
use App\Models\ObservationSupersession;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

/**
 * Lets a person correct a value.
 *
 * A correction is a new observation, not an edit: nothing is destroyed. The curated
 * row is marked `is_curated` so reads serve it ahead of any ingested source — a human
 * looking at the actual statistical yearbook should outrank an automated ingest — while
 * the source values remain current and visible as alternatives.
 */
class ObservationCurator
{
    /**
     * @param  array{value?: float|int|null, period_year?: int, status?: string, missing_reason?: string|null, note?: string|null}  $changes
     */
    public function curate(Observation $target, array $changes, ?int $userId = null): Observation
    {
        $source = Source::query()->where('code', 'manual_curation')->firstOrFail();
        $indicator = $target->indicator;
        $periodType = $target->period_type;

        $periodYear = (int) ($changes['period_year'] ?? $target->period_year);
        $hasValue = array_key_exists('value', $changes);
        $value = $hasValue ? $changes['value'] : $target->value;
        $status = $changes['status'] ?? ($value === null ? 'missing' : 'manual_entry');
        $missingReason = $changes['missing_reason'] ?? ($value === null ? 'unknown' : null);
        $note = $changes['note'] ?? null;

        $periodKey = Observation::makePeriodKey($periodType, $periodYear);
        [$periodStart, $periodEnd] = Observation::periodBounds($periodType, $periodYear);

        return DB::transaction(function () use (
            $target, $source, $indicator, $periodType, $periodYear, $periodKey,
            $periodStart, $periodEnd, $value, $status, $missingReason, $note, $userId
        ): Observation {
            $batch = IngestionBatch::create([
                'kind' => 'manual_edit',
                'status' => 'running',
                'user_id' => $userId,
                'started_at' => now(),
            ]);

            $contentHash = Observation::contentHash([
                'entity_id' => $target->entity_id,
                'indicator_id' => $target->indicator_id,
                'period_type' => $periodType,
                'period_key' => $periodKey,
                'source_id' => $source->id,
                'status' => $status,
                'value' => $value,
                'value_text' => null,
                'missing_reason' => $missingReason,
            ]);

            /*
             * Only an earlier curation of the same series point is superseded. The
             * source values stay current so the UI can still show "you corrected this
             * from X" — the curated row wins on read via `is_curated`, it does not
             * erase what the sources said.
             */
            $superseded = Observation::query()
                ->current()
                ->where('entity_id', $target->entity_id)
                ->where('indicator_id', $target->indicator_id)
                ->where('period_key', $periodKey)
                ->where('is_curated', true)
                ->get();

            $observation = Observation::create([
                'entity_id' => $target->entity_id,
                'indicator_id' => $target->indicator_id,
                'period_type' => $periodType,
                'period_key' => $periodKey,
                'period_year' => $periodYear,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'value' => $value,
                'unit' => $indicator->unit,
                'status' => $status,
                'is_actual' => $value !== null && $status !== 'missing',
                'period_is_assumed' => $target->period_is_assumed,
                'is_curated' => true,
                'missing_reason' => $missingReason,
                'raw_value' => $value === null ? null : (string) $value,
                'source_id' => $source->id,
                'source_dataset_id' => null,
                'source_snapshot_id' => null,
                'source_record_ref' => 'curation:observation:'.$target->id,
                'source_url' => null,
                'reference_note' => $note ?? 'Reviewed and corrected by a project editor.',
                'retrieved_at' => now(),
                'revision' => 1,
                'content_hash' => $contentHash,
                'notes' => $note,
                'import_batch_id' => $batch->id,
                'created_by' => $userId,
            ]);

            foreach ($superseded as $previous) {
                if ($previous->id === $observation->id) {
                    continue;
                }

                ObservationSupersession::create([
                    'superseded_id' => $previous->id,
                    'superseded_by_id' => $observation->id,
                    'reason' => 'correction',
                ]);
            }

            $batch->update([
                'status' => 'success',
                'observations_created' => 1,
                'observations_superseded' => $superseded->count(),
                'finished_at' => now(),
                'report' => [
                    'entity_id' => $target->entity_id,
                    'indicator_id' => $target->indicator_id,
                    'period_key' => $periodKey,
                    'superseded_observation_ids' => $superseded->pluck('id')->all(),
                ],
            ]);

            return $observation;
        });
    }
}
