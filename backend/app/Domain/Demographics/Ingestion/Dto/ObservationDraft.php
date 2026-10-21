<?php

namespace App\Domain\Demographics\Ingestion\Dto;

/**
 * One candidate observation produced by an adapter or importer, before it is
 * validated and written. Adapters describe what they found; the writer decides
 * whether it is new, unchanged, superseding or conflicting.
 */
class ObservationDraft
{
    public function __construct(
        public readonly int $entityId,
        public readonly int $indicatorId,
        public readonly string $periodType,
        public readonly int $periodYear,
        public readonly ?int $periodQuarter = null,
        public readonly ?int $periodMonth = null,
        public readonly int|float|string|null $value = null,
        public readonly ?string $valueText = null,
        public readonly ?string $unit = null,
        public readonly string $status = 'reported',
        public readonly bool $isEstimate = false,
        public readonly bool $isProjection = false,
        public readonly bool $periodIsAssumed = false,
        public readonly bool $isCurated = false,
        public readonly bool $isDerived = false,
        public readonly ?string $missingReason = null,
        public readonly ?string $rawValue = null,
        public readonly ?int $sourceId = null,
        public readonly ?int $sourceDatasetId = null,
        public readonly ?int $sourceSnapshotId = null,
        public readonly ?string $sourceRecordRef = null,
        public readonly ?string $sourceUrl = null,
        public readonly ?string $referenceNote = null,
        public readonly ?string $publishedDate = null,
        public readonly ?string $notes = null,
        public readonly ?int $qualityScore = null,
    ) {}
}
