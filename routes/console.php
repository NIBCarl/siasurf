<?php

use App\Console\Commands\SendReviewRequestEmails;
use App\Console\Commands\FetchTideDataCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule review request emails to run every hour
Schedule::command(SendReviewRequestEmails::class)
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/review-requests.log'));

// Fetch tide data from Stormglass.io once daily at 00:05 (uses 1 of 10 free API calls)
Schedule::command(FetchTideDataCommand::class)
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/stormglass-tides.log'));
