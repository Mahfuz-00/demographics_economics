<?php

namespace App\Domain\Demographics\Import;

use App\Domain\Demographics\Ingestion\Dto\ObservationDraft;
use App\Domain\Demographics\Ingestion\ObservationWriter;
use App\Domain\Demographics\Ingestion\SnapshotStore;
use App\Models\CsvColumnMapping;
use App\Models\Entity;
use App\Models\Indicator;
use App\Models\IngestionBatch;
use App\Models\IngestionQuarantine;
use App\Models\Source;
use App\Models\SourceDataset;
use App\Models\SourceSnapshot;
use App\Support\EntityNameNormalizer;
use InvalidArgumentException;
use League\Csv\Reader;
use Throwable;

/**
 * Imports the hand-maintained demographic spreadsheet.
 *
 * The file is treated as untrusted, unverified input: it is stored verbatim as a
 * snapshot, split into its constituent tables, read cell by cell, and anything that
 * cannot be read unambiguously is quarantined with a reason rather than coerced.
 * Original text is preserved in `raw_value`; reference periods come from the
 * source's own year columns, never invented.
 */
class CsvDemographicImporter
{
    public function __construct(
        private readonly CsvTableSplitter $splitter,
        private readonly ValueParser $parser,
        private readonly EntityResolver $resolver,
        private readonly ObservationWriter $writer,
        private readonly SnapshotStore $snapshots,
    ) {}

    /**
     * @param  string|null  $datasetCode  which bundled spreadsheet layout to apply
     */
    public function import(
        string $path,
        ?string $datasetCode = null,
        bool $recordMissing = true,
        ?int $userId = null,
    ): IngestionBatch {
        if (! is_file($path)) {
            throw new InvalidArgumentException("CSV file not found: {$path}");
        }

        $dataset = SourceDataset::query()
            ->with('source')
            ->where('code', $datasetCode ?? 'manual_demographic_csv')
            ->firstOrFail();

        $source = $dataset->source;
        $contents = EntityNameNormalizer::toUtf8((string) file_get_contents($path));

        $snapshot = $this->snapshots->store(
            $dataset,
            $contents,
            'file://'.str_replace('\\', '/', (string) realpath($path)),
            null,
            null,
            'text/csv',
        );

        $batch = IngestionBatch::create([
            'source_dataset_id' => $dataset->id,
            'kind' => 'csv_manual',
            'status' => 'running',
            'file_name' => basename($path),
            'file_hash' => hash('sha256', $contents),
            'user_id' => $userId,
            'started_at' => now(),
        ]);

        try {
            return $this->ingest($batch, $dataset, $source, $snapshot, $contents, $recordMissing);
        } catch (Throwable $error) {
            // A batch left in `running` forever is worse than one marked failed: the admin
            // view shows it as still in flight, so nobody can tell whether the import is
            // working or wedged. Record the failure, then let it propagate.
            $batch->update([
                'status' => 'failed',
                'finished_at' => now(),
                'report' => ['error' => $error->getMessage()],
            ]);

            throw $error;
        }
    }

    /**
     * The import proper, separated so that a failure anywhere inside it can be recorded
     * against the batch that was already opened.
     */
    private function ingest(
        IngestionBatch $batch,
        SourceDataset $dataset,
        Source $source,
        SourceSnapshot $snapshot,
        string $contents,
        bool $recordMissing,
    ): IngestionBatch {
        $rows = $this->readRows($contents);
        $tables = $this->splitter->split($rows, $dataset->config['layout']['sub_tables'] ?? []);

        $indicators = Indicator::all()->keyBy('id');
        $mappingsByTable = CsvColumnMapping::query()
            ->whereIn('sub_table', array_column($tables, 'key'))
            ->orderBy('source_column_index')
            ->get()
            ->groupBy('sub_table');

        $drafts = [];
        $quarantine = [];
        $seen = 0;
        $assumedPeriods = 0;

        /*
         * Several tables in the spreadsheet state values without stating a reference
         * year (the right-hand tables have a `Year` column that is mostly blank, and
         * `childlessness` has none at all). Rather than discard those figures, they are
         * dated to the year the document itself was compiled and flagged as assumed, so
         * they appear in the table while remaining explicitly marked as not sourced.
         */
        $compilationYear = $this->compilationYear($rows, $tables);

        foreach ($tables as $table) {
            $mappings = $mappingsByTable->get($table['key']);

            if ($mappings === null || $mappings->isEmpty()) {
                continue;
            }

            $entityMapping = $mappings->firstWhere('value_role', 'entity');

            if ($entityMapping === null) {
                continue;
            }

            $snapshotYearIndexes = $this->resolveColumnIndexes($table, $table['snapshot_year_columns']);
            $labelIndex = $mappings->firstWhere('value_role', 'label')?->source_column_index;

            for ($rowIndex = 1; $rowIndex < count($rows); $rowIndex++) {
                $row = $rows[$rowIndex];
                $entityCell = $this->cell($row, $table['start'] + $entityMapping->source_column_index);

                if ($this->parser->text($entityCell) === null) {
                    continue;
                }

                $entity = $this->resolver->resolve($entityCell);

                if ($entity === null) {
                    $this->quarantine($quarantine, $table['key'], $rowIndex, 'unknown_entity',
                        'Could not resolve "'.$this->parser->text($entityCell).'" to a known entity.', $row, $table);

                    continue;
                }

                $seen++;
                $this->rememberCsvLabel($entity, $labelIndex !== null ? $this->cell($row, $table['start'] + $labelIndex) : null);

                $rowSnapshotYear = $this->rowSnapshotYear($row, $table, $snapshotYearIndexes);

                foreach ($mappings as $mapping) {
                    if (! in_array($mapping->value_role, ['value', 'value_year'], true)) {
                        continue;
                    }

                    $indicator = $mapping->indicator_id !== null ? $indicators->get($mapping->indicator_id) : null;

                    if ($indicator === null) {
                        continue;
                    }

                    $usedAssumedPeriod = $this->handleValue(
                        $drafts, $quarantine, $table, $mapping, $indicator, $entity, $source, $dataset,
                        $snapshot->id, $row, $rowIndex, $rowSnapshotYear, $recordMissing, $compilationYear
                    );

                    if ($usedAssumedPeriod) {
                        $assumedPeriods++;
                    }
                }
            }
        }

        $result = $this->writer->write($batch, $drafts);
        $this->flushQuarantine($batch, $dataset, $quarantine);

        $batch->update([
            'status' => $result->rejected > 0 ? 'partial' : 'success',
            'rows_seen' => $seen,
            'observations_created' => $result->created,
            'observations_unchanged' => $result->unchanged,
            'observations_superseded' => $result->superseded,
            'quarantined' => count($quarantine),
            'conflicts' => $result->conflicts,
            'finished_at' => now(),
            'report' => [
                'sub_tables' => array_column($tables, 'key'),
                'data_rows' => max(count($rows) - 1, 0),
                'entity_rows_seen' => $seen,
                'compilation_year' => $compilationYear,
                'assumed_periods' => $assumedPeriods,
                'write' => $result->toArray(),
                'rejections' => array_slice($result->rejections, 0, 50),
                'quarantine_reasons' => collect($quarantine)->groupBy('reason_code')->map->count()->all(),
            ],
        ]);

        return $batch;
    }

    /**
     * @return bool whether the value had to be dated to the compilation year
     */
    private function handleValue(
        array &$drafts,
        array &$quarantine,
        array $table,
        CsvColumnMapping $mapping,
        Indicator $indicator,
        Entity $entity,
        $source,
        SourceDataset $dataset,
        int $snapshotId,
        array $row,
        int $rowIndex,
        ?int $rowSnapshotYear,
        bool $recordMissing,
        int $compilationYear,
    ): bool {
        $cell = $this->cell($row, $table['start'] + $mapping->source_column_index);
        $parsed = $this->parser->number($cell);

        // Some cells lead with the figure and follow it with a qualifier the schema has
        // no place for ("43.1/15-20" is 43.1% for the 15-20 age bracket). Take the
        // leading number and keep the rest as a note rather than discard the row.
        if ($parsed->isError() && $mapping->transform === 'leading_number') {
            if (preg_match('/^\s*(-?\d+(?:[.,]\d+)?)\s*(.*)$/', (string) $cell, $matches)) {
                $remainder = trim($matches[2]);

                $parsed = ParsedValue::value(
                    (float) str_replace(',', '.', $matches[1]),
                    $cell,
                    null,
                    'reported',
                    $remainder !== '' ? 'Source also states: '.$remainder : null
                );
            }
        }

        if ($parsed->isError()) {
            $this->quarantine($quarantine, $table['key'], $rowIndex, (string) $parsed->errorCode,
                (string) $parsed->note, $row, $table);

            return false;
        }

        $year = $this->resolvePeriodYear($mapping, $parsed, $row, $table, $rowSnapshotYear, $indicator);
        $periodIsAssumed = false;

        if ($year === null) {
            if (! $parsed->isValue()) {
                return false;
            }

            // A figure the source states but does not date. Keep it, mark the period
            // as assumed, and make the reason visible in the record itself.
            $year = $compilationYear;
            $periodIsAssumed = true;
        }

        if ($parsed->isMissing() && ! $recordMissing) {
            return false;
        }

        $note = $parsed->note;
        if ($mapping->transform === 'cumulative_note') {
            $note = trim(($note ?? '').' Cumulative total recorded by the source since the 1970s.');
        }

        if ($periodIsAssumed) {
            $note = trim(($note ?? '').sprintf(
                ' The source states this figure without a reference year; it has been dated to the spreadsheet compilation year (%d). Treat the period as assumed.',
                $compilationYear
            ));
        }

        $drafts[] = new ObservationDraft(
            entityId: $entity->id,
            indicatorId: $indicator->id,
            periodType: 'annual',
            periodYear: $year,
            value: $parsed->isValue() ? $parsed->value : null,
            unit: $indicator->unit,
            status: $parsed->isValue() ? $parsed->status : 'missing',
            isEstimate: $parsed->isEstimate,
            periodIsAssumed: $periodIsAssumed,
            missingReason: $parsed->isMissing() ? ($parsed->missingReason ?? 'not_collected') : null,
            rawValue: $parsed->rawValue,
            sourceId: $source->id,
            sourceDatasetId: $dataset->id,
            sourceSnapshotId: $snapshotId,
            sourceRecordRef: 'csv:'.$table['key'].':row'.($rowIndex + 1),
            sourceUrl: null,
            referenceNote: $note,
        );

        return $periodIsAssumed;
    }

    /**
     * The year the document itself was compiled: the latest reference year stated
     * anywhere in it. Used only to date figures the source left undated.
     */
    private function compilationYear(array $rows, array $tables): int
    {
        $latest = null;

        foreach ($tables as $table) {
            $indexes = $this->resolveColumnIndexes($table, $table['snapshot_year_columns']);

            if ($indexes === []) {
                continue;
            }

            for ($rowIndex = 1; $rowIndex < count($rows); $rowIndex++) {
                $year = $this->rowSnapshotYear($rows[$rowIndex], $table, $indexes);

                if ($year !== null && ($latest === null || $year > $latest)) {
                    $latest = $year;
                }
            }
        }

        return $latest ?? (int) now()->year;
    }

    private function resolvePeriodYear(
        CsvColumnMapping $mapping,
        ParsedValue $parsed,
        array $row,
        array $table,
        ?int $rowSnapshotYear,
        Indicator $indicator,
    ): ?int {
        if ($parsed->isValue() && $parsed->year !== null) {
            return $parsed->year;
        }

        if ($mapping->year_source_column_index !== null) {
            $fromColumn = $this->parser->year($this->cell($row, $table['start'] + $mapping->year_source_column_index));

            if ($fromColumn->isValue()) {
                return $fromColumn->year;
            }
        }

        $transform = (string) $mapping->transform;

        if (str_starts_with($transform, 'fixed_year:')) {
            return (int) substr($transform, strlen('fixed_year:'));
        }

        if ($rowSnapshotYear !== null) {
            return $rowSnapshotYear;
        }

        // An indicator whose value is itself a year dates itself.
        if ($indicator->value_kind === 'year' && $parsed->isValue() && $parsed->value !== null) {
            $candidate = (int) $parsed->value;

            if ($candidate >= 1500 && $candidate <= 2200) {
                return $candidate;
            }
        }

        return null;
    }

    private function rowSnapshotYear(array $row, array $table, array $indexes): ?int
    {
        foreach ($indexes as $index) {
            $parsed = $this->parser->year($this->cell($row, $table['start'] + $index));

            if ($parsed->isValue()) {
                return $parsed->year;
            }
        }

        return null;
    }

    private function resolveColumnIndexes(array $table, array $labels): array
    {
        $indexes = [];

        foreach ($labels as $label) {
            $normalized = EntityNameNormalizer::columnKey($label);

            foreach ($table['columns'] as $index => $column) {
                if (EntityNameNormalizer::columnKey($column) === $normalized) {
                    $indexes[] = $index;
                    break;
                }
            }
        }

        return $indexes;
    }

    private function rememberCsvLabel(Entity $entity, ?string $label): void
    {
        $label = $this->parser->text($label);

        if ($label === null || $entity->csv_label !== null) {
            return;
        }

        $entity->csv_label = $label;
        $entity->save();
    }

    private function cell(array $row, int $index): ?string
    {
        $value = $row[$index] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * Quarantine entries are collapsed by (sub-table, reason, offending value) so a
     * systematic problem produces one row with a count rather than hundreds.
     */
    private function quarantine(
        array &$quarantine,
        string $subTable,
        int $rowIndex,
        string $reasonCode,
        string $detail,
        array $row,
        array $table,
    ): void {
        $offending = $this->cell($row, $table['start']) ?? '';
        $signature = $subTable.'|'.$reasonCode.'|'.$offending;

        if (isset($quarantine[$signature])) {
            $quarantine[$signature]['count']++;

            return;
        }

        $quarantine[$signature] = [
            'sub_table' => $subTable,
            'row_number' => $rowIndex + 1,
            'reason_code' => $reasonCode,
            'reason_detail' => $detail,
            'raw_row' => array_slice(array_map(static fn ($c) => is_string($c) ? mb_substr($c, 0, 200) : $c, $row), 0, 60),
            'count' => 1,
        ];
    }

    private function flushQuarantine(IngestionBatch $batch, SourceDataset $dataset, array $quarantine): void
    {
        foreach ($quarantine as $entry) {
            IngestionQuarantine::create([
                'batch_id' => $batch->id,
                'source_dataset_id' => $dataset->id,
                'sub_table' => $entry['sub_table'],
                'row_number' => $entry['row_number'],
                'reason_code' => $entry['reason_code'],
                'reason_detail' => $entry['count'] > 1
                    ? $entry['reason_detail'].' ('.$entry['count'].' rows share this problem.)'
                    : $entry['reason_detail'],
                'raw_row' => $entry['raw_row'],
            ]);
        }
    }

    private function readRows(string $contents): array
    {
        $reader = Reader::createFromString($contents);
        $reader->setDelimiter(',');
        $reader->setEnclosure('"');
        $reader->skipEmptyRecords();

        $rows = [];

        foreach ($reader->getRecords() as $record) {
            $rows[] = array_map(static fn ($cell) => $cell === null ? null : (string) $cell, $record);
        }

        return $rows;
    }
}
