<?php

namespace App\Domain\Demographics\Ingestion\Contracts;

use App\Domain\Demographics\Ingestion\Dto\ObservationDraft;
use App\Domain\Demographics\Ingestion\Dto\RawDataset;
use App\Models\SourceDataset;

/**
 * Every live source plugs in through this interface.
 *
 * `fetch` is responsible for retrieving bytes and keeping a snapshot of them;
 * `normalize` turns that snapshot into observation drafts. Keeping the two apart
 * means a payload can be re-normalised later — after a parser fix, say — without
 * hitting the network again, and it keeps network concerns out of parsing.
 */
interface SourceAdapter
{
    /**
     * Retrieve the dataset and store the raw payload as a snapshot.
     *
     * `$options` carries per-run scope — for example limiting a scrape to a single
     * entity when the user asks for one country's data.
     *
     * @param  array<string, mixed>  $options
     */
    public function fetch(SourceDataset $dataset, array $options = []): RawDataset;

    /**
     * Turn a fetched payload into observation drafts.
     *
     * @return iterable<ObservationDraft>
     */
    public function normalize(RawDataset $raw): iterable;

    /**
     * Human-readable identifier used in the source registry and CLI.
     */
    public function key(): string;
}
