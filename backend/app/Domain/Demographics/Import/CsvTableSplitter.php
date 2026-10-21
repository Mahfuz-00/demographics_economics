<?php

namespace App\Domain\Demographics\Import;

use App\Support\EntityNameNormalizer;

/**
 * Splits the source sheet into its constituent tables.
 *
 * The spreadsheet holds several tables side by side, and several of them begin
 * with a column literally called `Country`. Position is what tells them apart:
 * sub-tables are located by scanning the header left to right for each table's
 * known first column, advancing a cursor, so the 1st, 2nd, 3rd ... occurrence of
 * `Country` map onto the 1st, 2nd, 3rd ... sub-table in the definition order.
 */
class CsvTableSplitter
{
    /**
     * @param  array<int, array<int, string|null>>  $rows  raw rows, header first
     * @param  array<int, array<string, mixed>>  $structure  sub-table definitions in header order
     * @return array<int, array<string, mixed>>
     */
    public function split(array $rows, array $structure): array
    {
        if ($rows === []) {
            return [];
        }

        $header = array_map(static fn ($cell): string => (string) $cell, $rows[0]);
        $cursor = 0;
        $tables = [];

        foreach ($structure as $definition) {
            $start = $this->locate($header, (string) $definition['first_label'], $cursor);

            if ($start === null) {
                continue;
            }

            $columns = [];
            for ($offset = 0; $offset < (int) $definition['column_count']; $offset++) {
                $columns[$offset] = $header[$start + $offset] ?? '';
            }

            $tables[] = [
                'key' => $definition['key'],
                'start' => $start,
                'columns' => $columns,
                'snapshot_year_columns' => $definition['snapshot_year_columns'] ?? [],
            ];

            $cursor = $start + (int) $definition['column_count'];
        }

        return $tables;
    }

    private function locate(array $header, string $firstLabel, int $from): ?int
    {
        $needle = EntityNameNormalizer::columnKey($firstLabel);

        for ($index = $from; $index < count($header); $index++) {
            if (EntityNameNormalizer::columnKey($header[$index]) === $needle) {
                return $index;
            }
        }

        return null;
    }
}
