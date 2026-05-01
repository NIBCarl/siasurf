<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StormglassService;
use Illuminate\Console\Command;

/**
 * Fetches tide data from Stormglass.io and caches it for 7 days.
 * Designed to run once daily at 00:05 via Laravel Scheduler.
 * Uses only 1 API request per execution (out of 10/day free tier budget).
 */
class FetchTideDataCommand extends Command
{
    protected $signature = 'stormglass:fetch-tides {--days=7 : Number of days ahead to fetch}';

    protected $description = 'Fetch tide extreme data from Stormglass.io for Siargao and cache it';

    public function handle(StormglassService $service): int
    {
        $days = (int) $this->option('days');

        $this->info("Fetching tide data for the next {$days} days...");

        $cached = $service->fetchAndCacheTideData($days);

        if ($cached > 0) {
            $this->info("✅ Successfully cached tide data for {$cached} days.");
            return self::SUCCESS;
        }

        $this->error('❌ Failed to fetch tide data. Check logs for details.');
        return self::FAILURE;
    }
}
