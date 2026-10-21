<?php

namespace App\Domain\Demographics\Ingestion;

use App\Domain\Demographics\Ingestion\Dto\ObservationDraft;
use App\Domain\Demographics\Ingestion\Dto\WriteResult;
use App\Models\Indicator;
use App\Models\IngestionBatch;
use App\Models\IngestionQuarantine;
use App\Models\Observation;
use App\Models\ObservationSupersession;

/**
 * The single point at which observations enter the database.
 *
 * Behaviour it guarantees:
 *   - observations are never updated in place; a changed value becomes a new revision
 *     and the old row is linked to it through `observation_supersessions`
 *   - identical data re-ingested is skipped, not duplicated
 *   - disagreement between two sources is recorded, and the higher-ranked source is
 *     served as primary while both rows survive
 *   - sub-annual observations are refused unless the indicator is declared to be
 *     published at that frequency, so annual series cannot be dressed up as quarterly
 */
class ObservationWriter
{
    public function __construct(private readonly ConflictDetector $conflictDetector) {}

    /**
     * @param  iterable<ObservationDraft>  $drafts
     */
    public function write(IngestionBatch $batch, iterable $drafts): WriteResult
    {
        $result = new WriteResult;
        $indicators = [];

        foreach ($drafts as $draft) {
            $indicator = $indicators[$draft->indicatorId]
                ??= Indicator::find($draft->indicatorId);

            if ($indicator === null) {
                $result->reject('unknown_indicator', 'Indicator '.$draft->indicatorId.' does not exist.');

                continue;
            }

            if ($this->violatesFrequency($draft, $indicator)) {
                $reason = 'unsupported_frequency';
                $detail = sprintf(
                    '%s is not published at %s frequency; refusing to disaggregate an annual series.',
                    $indicator->code,
                    $draft->periodType
                );

                $result->reject($reason, $detail);
                $this->quarantine($batch, $reason, $detail, $draft);

                continue;
            }

            $this->writeOne($batch, $draft, $indicator, $result);
        }

        return $result;
    }

    private function writeOne(IngestionBatch $batch, ObservationDraft $draft, Indicator $indicator, WriteResult $result): void
    {
        $periodKey = Observation::makePeriodKey(
            $draft->periodType,
            $draft->periodYear,
            $draft->periodQuarter,
            $draft->periodMonth
        );

        $sourceId = $draft->sourceId;

        if ($sourceId === null) {
            $result->reject('missing_source', 'Draft has no source; every observation must be attributable.');

            return;
        }

        $hashParts = [
            'entity_id' => $draft->entityId,
            'indicator_id' => $draft->indicatorId,
            'period_type' => $draft->periodType,
            'period_key' => $periodKey,
            'source_id' => $sourceId,
            'status' => $draft->status,
            'value' => $draft->value,
            'value_text' => $draft->valueText,
            'missing_reason' => $draft->missingReason,
        ];

        $contentHash = Observation::contentHash($hashParts);

        if (Observation::where('content_hash', $contentHash)->exists()) {
            $result->unchanged++;

            return;
        }

        $previous = Observation::query()
            ->current()
            ->where('entity_id', $draft->entityId)
            ->where('indicator_id', $draft->indicatorId)
            ->where('period_key', $periodKey)
            ->where('source_id', $sourceId)
            ->orderByDesc('revision')
            ->first();

        [$periodStart, $periodEnd] = Observation::periodBounds(
            $draft->periodType,
            $draft->periodYear,
            $draft->periodQuarter,
            $draft->periodMonth
        );

        $observation = Observation::create([
            'entity_id' => $draft->entityId,
            'indicator_id' => $draft->indicatorId,
            'period_type' => $draft->periodType,
            'period_key' => $periodKey,
            'period_year' => $draft->periodYear,
            'period_quarter' => $draft->periodQuarter,
            'period_month' => $draft->periodMonth,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'value' => $draft->value,
            'value_text' => $draft->valueText,
            'unit' => $draft->unit ?? $indicator->unit,
            'status' => $draft->status,
            'is_estimate' => $draft->isEstimate,
            'is_projection' => $draft->isProjection,
            'is_actual' => ! $draft->isEstimate && ! $draft->isProjection && $draft->status !== 'missing',
            'period_is_assumed' => $draft->periodIsAssumed,
            'is_curated' => $draft->isCurated,
            'is_derived' => $draft->isDerived,
            'missing_reason' => $draft->missingReason,
            'raw_value' => $draft->rawValue,
            'source_id' => $sourceId,
            'source_dataset_id' => $draft->sourceDatasetId,
            'source_snapshot_id' => $draft->sourceSnapshotId,
            'source_record_ref' => $draft->sourceRecordRef,
            'source_url' => $draft->sourceUrl,
            'reference_note' => $draft->referenceNote,
            'published_date' => $draft->publishedDate,
            'retrieved_at' => now(),
            'revision' => $previous ? $previous->revision + 1 : 1,
            'content_hash' => $contentHash,
            'quality_score' => $draft->qualityScore,
            'notes' => $draft->notes,
            'import_batch_id' => $batch->id,
        ]);

        if ($previous) {
            ObservationSupersession::create([
                'superseded_id' => $previous->id,
                'superseded_by_id' => $observation->id,
                'reason' => 'revision',
            ]);

            $result->superseded++;
        }

        $this->conflictDetector->detect($observation, $result);
        $result->created++;
    }

    private function violatesFrequency(ObservationDraft $draft, Indicator $indicator): bool
    {
        if (! in_array($draft->periodType, ['quarterly', 'monthly'], true)) {
            return false;
        }

        return ! $indicator->supports_disaggregation;
    }

    private function quarantine(IngestionBatch $batch, string $reason, string $detail, ObservationDraft $draft): void
    {
        IngestionQuarantine::create([
            'batch_id' => $batch->id,
            'source_dataset_id' => $draft->sourceDatasetId,
            'reason_code' => $reason,
            'reason_detail' => $detail,
            'raw_row' => [
                'entity_id' => $draft->entityId,
                'indicator_id' => $draft->indicatorId,
                'period_type' => $draft->periodType,
                'period_year' => $draft->periodYear,
                'value' => $draft->value,
            ],
        ]);
    }
}
