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

// FR 1.9.3 - safety net sweep for booking requests the instructor never answered
Schedule::command(\App\Console\Commands\ExpireStaleBookingRequests::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/booking-acceptance.log'));

// A suspension is a timed penalty. Without this sweep an auto-suspended
// instructor stayed hidden from search forever after the month elapsed.
Schedule::command(\App\Console\Commands\LiftExpiredSuspensions::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/instructor-suspensions.log'));

// BR-4.6 - legal documents are kept for seven years, then destroyed.
Schedule::command(\App\Console\Commands\CleanupExpiredWaivers::class)
    ->monthlyOn(1, '02:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/waiver-retention.log'));
