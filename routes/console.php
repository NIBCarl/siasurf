<?php

use App\Console\Commands\SendReviewRequestEmails;
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
