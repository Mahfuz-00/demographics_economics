<?php

namespace App\Console\Commands;

use App\Domain\Demographics\Import\CsvDemographicImporter;
use App\Models\IngestionBatch;
use Illuminate\Console\Command;

class ImportDemographicsCsvCommand extends Command
{
    /**
     * The spreadsheets bundled with the project, and what each one is.
     */
    private const BUNDLED = [
        'manual_demographic_csv' => 'Demographics(Demographic).csv',
        'manual_economy_csv' => 'Demographics(Economy).csv',
        'manual_debt_csv' => 'Demographics(Debt).csv',
        'manual_indicator_csv' => 'Demographics(Indicator).csv',
    ];

    protected $signature = 'demographics:import-csv
        {path? : Path to the spreadsheet (defaults to the bundled copy)}
        {--dataset=manual_demographic_csv : Which layout to apply}
        {--all : Import every bundled spreadsheet}
        {--no-missing : Skip writing explicit "missing" observations for blank cells}
        {--user= : Attribute the import to a user id}';

    protected $description = 'Import a hand-maintained spreadsheet using its stored layout';

    public function handle(CsvDemographicImporter $importer): int
    {
        if ($this->option('all')) {
            return $this->importAll($importer);
        }

        return $this->importOne(
            $importer,
            (string) $this->option('dataset'),
            $this->argument('path') ?: $this->bundledPath((string) $this->option('dataset')),
        );
    }

    private function importAll(CsvDemographicImporter $importer): int
    {
        $failures = 0;

        foreach (self::BUNDLED as $datasetCode => $fileName) {
            $this->newLine();
            $this->info("── {$fileName}");

            if ($this->importOne($importer, $datasetCode, $this->bundledPath($datasetCode)) === self::FAILURE) {
                $failures++;
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function importOne(CsvDemographicImporter $importer, string $datasetCode, string $path): int
    {
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $this->info("Importing {$path}");

        $batch = $importer->import(
            $path,
            datasetCode: $datasetCode,
            recordMissing: ! $this->option('no-missing'),
            userId: $this->option('user') !== null ? (int) $this->option('user') : null,
        );

        $this->renderReport($batch);

        return $batch->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function bundledPath(string $datasetCode): string
    {
        $fileName = self::BUNDLED[$datasetCode] ?? self::BUNDLED['manual_demographic_csv'];

        return database_path('seed-data/'.$fileName);
    }

    private function renderReport(IngestionBatch $batch): void
    {
        $report = $batch->report ?? [];

        $this->table(['Metric', 'Value'], [
            ['Entities matched', $batch->rows_seen],
            ['Observations created', $batch->observations_created],
            ['Already present (unchanged)', $batch->observations_unchanged],
            ['Observations superseded', $batch->observations_superseded],
            ['Conflicts recorded', $batch->conflicts],
            ['Quarantine groups', $batch->quarantined],
            ['Assumed periods', $report['assumed_periods'] ?? 0],
        ]);

        $reasons = $report['quarantine_reasons'] ?? [];

        if ($reasons !== []) {
            $this->newLine();
            $this->line('Quarantine reasons:');

            foreach ($reasons as $reason => $count) {
                $this->line(sprintf('  %-24s %d', $reason, $count));
            }
        }

        $this->info("Batch #{$batch->id} finished with status: {$batch->status}");
    }
}
