<?php

namespace Database\Seeders;

use App\Models\CsvColumnMapping;
use App\Models\Indicator;
use App\Models\SourceDataset;
use App\Support\EntityNameNormalizer;
use Illuminate\Database\Seeder;

/**
 * Projects each spreadsheet's layout definition into the database, so the importer reads
 * its structure and column semantics from one auditable place rather than from code.
 *
 * One layout file per bundled spreadsheet; all four share the same mapping table and the
 * same importer.
 */
class CsvColumnMappingSeeder extends Seeder
{
    private const LAYOUTS = [
        'manual_demographic_csv' => 'demographic_csv_layout.php',
        'manual_economy_csv' => 'economy_csv_layout.php',
        'manual_debt_csv' => 'debt_csv_layout.php',
        'manual_indicator_csv' => 'indicator_csv_layout.php',
    ];

    public function run(): void
    {
        $indicatorIds = Indicator::query()->pluck('id', 'code');

        foreach (self::LAYOUTS as $datasetCode => $file) {
            $path = database_path('seed-data/'.$file);

            if (! is_file($path)) {
                continue;
            }

            $layout = require $path;
            $structure = [];

            foreach ($layout['sub_tables'] as $order => $subTable) {
                $labelIndex = [];
                foreach ($subTable['columns'] as $index => $column) {
                    $labelIndex[EntityNameNormalizer::columnKey($column['label'])] = $index;
                }

                foreach ($subTable['columns'] as $index => $column) {
                    $indicatorCode = $column['indicator'] ?? null;

                    // Year sources are usually named, but a sheet that repeats the label
                    // "Year" needs an explicit index instead.
                    $yearColumnIndex = $column['year_column_index'] ?? null;

                    if ($yearColumnIndex === null && isset($column['year_column'])) {
                        $yearColumnIndex = $labelIndex[EntityNameNormalizer::columnKey($column['year_column'])] ?? null;
                    }

                    $transform = $column['transform'] ?? null;
                    if ($transform !== null && isset($column['transform_arg'])) {
                        $transform .= ':'.$column['transform_arg'];
                    }

                    CsvColumnMapping::updateOrCreate(
                        ['sub_table' => $subTable['key'], 'source_column_index' => $index],
                        [
                            'sub_table_order' => $order,
                            'csv_column' => $column['label'],
                            'indicator_id' => $indicatorCode ? $indicatorIds->get($indicatorCode) : null,
                            'value_role' => $column['role'],
                            'year_source_column_index' => $yearColumnIndex,
                            'transform' => $transform,
                            'notes' => $column['notes'] ?? null,
                        ]
                    );
                }

                $structure[] = [
                    'key' => $subTable['key'],
                    'first_label' => $subTable['first_label'],
                    'column_count' => count($subTable['columns']),
                    'snapshot_year_columns' => $subTable['snapshot_year_columns'] ?? [],
                ];
            }

            SourceDataset::where('code', $datasetCode)->update([
                'config' => [
                    'layout' => [
                        'has_header_row' => true,
                        'encoding' => 'auto',
                        'sub_tables' => $structure,
                    ],
                ],
            ]);
        }
    }
}
