<?php

namespace App\Domain\Demographics\Ingestion\Dto;

use App\Models\SourceDataset;
use App\Models\SourceFetchRun;
use App\Models\SourceSnapshot;

/**
 * What a fetch produced: the stored snapshot, plus whatever in-memory handle the
 * adapter needs to normalise it (decoded JSON, a path to an unzipped CSV, ...).
 */
class RawDataset
{
    public function __construct(
        public readonly SourceDataset $dataset,
        public readonly SourceSnapshot $snapshot,
        public readonly ?SourceFetchRun $run = null,
        public readonly mixed $payload = null,
        /**
         * Deliberately not readonly: adapters annotate it while normalising, so counts
         * like "values dropped as implausible" end up in the fetch run's report.
         */
        public array $meta = [],
    ) {}
}
