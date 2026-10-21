<?php

use App\Jobs\FetchSourceDatasetJob;
use App\Models\SourceDataset;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Data freshness schedule
|--------------------------------------------------------------------------
| Every hour we look for datasets that are due according to their own declared
| update frequency (`next_run_at`), and queue a fetch for each. Because the
| payload is content-hashed, a source that has not changed costs one request and
| writes nothing.
|
| A dataset is only queried when it is due, so the World Bank's weekly cadence and
| Wikipedia's monthly cadence are both honoured without separate cron entries.
*/
Schedule::call(function (): void {
    SourceDataset::query()
        ->enabled()
        ->whereNotNull('adapter_key')
        ->where(function ($query): void {
            $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', now());
        })
        ->orderBy('id')
        ->each(function (SourceDataset $dataset): void {
            FetchSourceDatasetJob::dispatch($dataset->id, 'schedule');
        });
})->hourly()->name('demographics:fetch-due')->withoutOverlapping();
