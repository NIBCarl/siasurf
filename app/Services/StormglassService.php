<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Service for fetching real-time tide data from Stormglass.io API.
 *
 * Design: Uses aggressive caching (48h per day) to stay within the
 * free tier limit of 10 requests/day. A scheduled command pre-fetches
 * data daily; an on-demand fallback handles cache misses in local dev.
 */
class StormglassService
{
    private const CACHE_PREFIX = 'stormglass_tides_';
    private const CACHE_TTL_SECONDS = 172800; // 48 hours
    private const LOW_TIDE_THRESHOLD = 0.3; // meters — below this is dangerous for beginners
    private const SIARGAO_LAT = 9.814;
    private const SIARGAO_LNG = 126.164;

    private ?string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.stormglass.key');
    }

    /**
     * Fetch tide extremes from Stormglass for a date range and cache per day.
     * This is called by the scheduled command (1 call/day for 7 days of data).
     *
     * @return int Number of days successfully cached
     */
    public function fetchAndCacheTideData(int $daysAhead = 7): int
    {
        if (empty($this->apiKey)) {
            Log::warning('StormglassService: API key not configured.');
            return 0;
        }

        $start = Carbon::now('Asia/Manila')->startOfDay();
        $end = $start->copy()->addDays($daysAhead)->endOfDay();

        try {
            $response = Http::timeout(10)
                ->withOptions(['verify' => false])
                ->withHeaders(['Authorization' => $this->apiKey])
                ->get('https://api.stormglass.io/v2/tide/extremes/point', [
                    'lat' => self::SIARGAO_LAT,
                    'lng' => self::SIARGAO_LNG,
                    'start' => $start->toIso8601String(),
                    'end' => $end->toIso8601String(),
                ]);

            if (!$response->successful()) {
                Log::error('StormglassService: API returned ' . $response->status(), [
                    'body' => $response->body(),
                ]);
                return 0;
            }

            $extremes = $response->json('data', []);

            if (empty($extremes)) {
                Log::warning('StormglassService: API returned empty data.');
                return 0;
            }

            // Group extremes by date (Asia/Manila timezone)
            $grouped = [];
            foreach ($extremes as $extreme) {
                $date = Carbon::parse($extreme['time'])->setTimezone('Asia/Manila')->format('Y-m-d');
                $grouped[$date][] = [
                    'type' => $extreme['type'], // "high" or "low"
                    'height' => round($extreme['height'], 2),
                    'time' => Carbon::parse($extreme['time'])->setTimezone('Asia/Manila')->format('g:i A'),
                    'timestamp' => Carbon::parse($extreme['time'])->setTimezone('Asia/Manila')->timestamp,
                ];
            }

            // Cache each day separately
            $daysCached = 0;
            foreach ($grouped as $date => $tides) {
                Cache::put(
                    self::CACHE_PREFIX . $date,
                    $tides,
                    self::CACHE_TTL_SECONDS
                );
                $daysCached++;
            }

            Log::info("StormglassService: Cached tide data for {$daysCached} days.");
            return $daysCached;

        } catch (\Exception $e) {
            Log::error('StormglassService: Exception fetching tide data.', [
                'message' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get the current tide status for today (used by Student Dashboard).
     * Returns a human-readable string like "High Tide @ 2:30 PM".
     */
    public function getCurrentTide(): string
    {
        $today = Carbon::now('Asia/Manila')->format('Y-m-d');
        $tides = Cache::get(self::CACHE_PREFIX . $today);

        // On-demand fallback: if cache is empty, fetch just today
        if ($tides === null) {
            $fetched = $this->fetchAndCacheTideData(1);
            if ($fetched > 0) {
                $tides = Cache::get(self::CACHE_PREFIX . $today);
            }
        }

        if (empty($tides)) {
            return 'Tide data unavailable';
        }

        // Find the nearest upcoming tide extreme
        $now = Carbon::now('Asia/Manila')->timestamp;
        $nearest = null;
        $minDiff = PHP_INT_MAX;

        foreach ($tides as $tide) {
            $diff = $tide['timestamp'] - $now;
            // Prefer upcoming tides, but also consider recent past (within 1 hour)
            if ($diff > -3600 && abs($diff) < $minDiff) {
                $minDiff = abs($diff);
                $nearest = $tide;
            }
        }

        if ($nearest === null) {
            // All tides have passed today — show the last one
            $nearest = end($tides);
        }

        $label = ucfirst($nearest['type']); // "High" or "Low"
        return "{$label} Tide @ {$nearest['time']} ({$nearest['height']}m)";
    }

    /**
     * Get a tide warning for a specific date and time period (used by Booking Flow).
     * Returns null if conditions are safe, or a warning string if low tide is dangerous.
     */
    public function getTideWarning(string $date, string $startTime): ?string
    {
        $tides = Cache::get(self::CACHE_PREFIX . $date);

        // If no cached data for this date, try fetching
        if ($tides === null) {
            $fetched = $this->fetchAndCacheTideData(7);
            $tides = Cache::get(self::CACHE_PREFIX . $date);
        }

        if (empty($tides)) {
            return null; // No data — don't block the booking
        }

        // Determine the time window for the session (1-hour slot)
        $sessionStart = Carbon::parse("{$date} {$startTime}", 'Asia/Manila');
        $sessionEnd = $sessionStart->copy()->addHour();

        // Check if any low tide extreme falls within or near the session window (±30 min buffer)
        $bufferStart = $sessionStart->copy()->subMinutes(30)->timestamp;
        $bufferEnd = $sessionEnd->copy()->addMinutes(30)->timestamp;

        foreach ($tides as $tide) {
            if (
                $tide['type'] === 'low' &&
                $tide['height'] < self::LOW_TIDE_THRESHOLD &&
                $tide['timestamp'] >= $bufferStart &&
                $tide['timestamp'] <= $bufferEnd
            ) {
                return "⚠️ Low tide ({$tide['height']}m) expected at {$tide['time']} — shallow reef conditions. Extra caution advised for beginners.";
            }
        }

        return null; // Safe conditions
    }

    /**
     * Get all tide extremes for a specific date (raw data).
     */
    public function getTidesForDate(string $date): array
    {
        return Cache::get(self::CACHE_PREFIX . $date, []);
    }

    /**
     * Get all upcoming cached tide data for the next N days.
     */
    public function getUpcomingTides(int $daysAhead = 7): array
    {
        $start = Carbon::now('Asia/Manila');
        $tides = [];
        for ($i = 0; $i < $daysAhead; $i++) {
            $date = $start->copy()->addDays($i)->format('Y-m-d');
            $dayTides = Cache::get(self::CACHE_PREFIX . $date);
            if ($dayTides !== null) {
                $tides[$date] = $dayTides;
            }
        }
        
        // Fallback: If we have absolutely no data, try to fetch it
        if (empty($tides)) {
             $this->fetchAndCacheTideData($daysAhead);
             for ($i = 0; $i < $daysAhead; $i++) {
                $date = $start->copy()->addDays($i)->format('Y-m-d');
                $dayTides = Cache::get(self::CACHE_PREFIX . $date);
                if ($dayTides !== null) {
                    $tides[$date] = $dayTides;
                }
            }
        }
        
        return $tides;
    }
}
