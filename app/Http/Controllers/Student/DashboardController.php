<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Enums\BookingStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Services\StormglassService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $user = auth()->user();

        // 1. Active Session (Currently ongoing)
        $activeSession = Booking::with(['instructor', 'surfSpot'])
            ->where('student_id', $user->id)
            ->where('status', BookingStatus::InProgress)
            ->whereNotNull('started_at')
            ->whereNull('completed_at')
            ->first();

        // 2. Upcoming Bookings
        $upcomingBookings = Booking::with(['instructor', 'surfSpot'])
            ->where('student_id', $user->id)
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed])
            ->whereNull('started_at')
            ->whereDate('date', '>=', now())
            ->orderBy('date', 'asc')
            ->orderBy('time_period', 'asc')
            ->take(4)
            ->get();

        // 3. Past Instructors (for Quick Re-book)
        $pastInstructors = Booking::with(['instructor.instructorProfile'])
            ->where('student_id', $user->id)
            ->where('status', BookingStatus::Completed)
            ->latest()
            ->get()
            ->unique('instructor_id')
            ->take(3)
            ->map(fn($booking) => $booking->instructor);

        // 4. Real Surf & Weather Data for Siargao via Open-Meteo + Stormglass
        $stormglass = app(StormglassService::class);
        
        // Weather & wave data from Open-Meteo (free, unlimited)
        // Cached for 30 minutes to ensure fast page loads
        $surfReport = Cache::remember('siargao_surf_report', 1800, function () use ($stormglass) {
            try {
                $lat = 9.814;
                $lng = 126.164;
                
                // Weather API Request (withOptions for local XAMPP SSL issues)
                $weatherResponse = Http::timeout(5)->withOptions(['verify' => false])->get("https://api.open-meteo.com/v1/forecast", [
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'current' => 'temperature_2m,weather_code,wind_speed_10m,wind_direction_10m',
                    'timezone' => 'Asia/Manila'
                ]);
                
                // Marine API Request
                $marineResponse = Http::timeout(5)->withOptions(['verify' => false])->get("https://marine-api.open-meteo.com/v1/marine", [
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'current' => 'wave_height,wave_direction,wave_period',
                    'timezone' => 'Asia/Manila'
                ]);

                if ($weatherResponse->successful() && $marineResponse->successful()) {
                    $weatherData = $weatherResponse->json('current');
                    $marineData = $marineResponse->json('current');
                    
                    $condition = $this->getWeatherDescription($weatherData['weather_code'] ?? 0);
                    $icon = ($weatherData['weather_code'] ?? 0) > 50 ? 'rain' : 'sun';
                    $windDir = $this->getWindDirection($weatherData['wind_direction_10m'] ?? 0);

                    return [
                        'spot' => 'Cloud 9, Siargao',
                        'height' => isset($marineData['wave_height']) ? $marineData['wave_height'] . 'm' : 'Flat',
                        'condition' => $condition,
                        'wind' => round($weatherData['wind_speed_10m'] ?? 0) . 'km/h ' . $windDir,
                        'temp' => round($weatherData['temperature_2m'] ?? 0) . '°C',
                        'icon' => $icon
                    ];
                }
            } catch (\Exception $e) {
                // Return fallback below on failure
            }

            return [
                'spot' => 'Cloud 9, Siargao',
                'height' => '—',
                'condition' => 'Data Unavailable',
                'wind' => '—',
                'temp' => '—',
                'icon' => 'wave'
            ];
        });

        // Tide data from Stormglass (real-time, accurate — fetched separately to avoid coupling)
        $surfReport['tide'] = $stormglass->getCurrentTide();

        return Inertia::render('Student/Dashboard', [
            'activeSession' => $activeSession,
            'upcomingBookings' => $upcomingBookings,
            'pastInstructors' => $pastInstructors,
            'surfReport' => $surfReport,
        ]);
    }

    /**
     * Map WMO Weather codes to readable descriptions
     */
    private function getWeatherDescription(int $code): string
    {
        $codes = [
            0 => 'Clear sky',
            1 => 'Mainly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
            45 => 'Fog', 48 => 'Depositing rime fog',
            51 => 'Light drizzle', 53 => 'Moderate drizzle', 55 => 'Dense drizzle',
            61 => 'Slight rain', 63 => 'Moderate rain', 65 => 'Heavy rain',
            80 => 'Slight rain showers', 81 => 'Moderate rain showers', 82 => 'Violent rain showers',
            95 => 'Thunderstorm', 96 => 'Thunderstorm & hail'
        ];
        return $codes[$code] ?? 'Variable';
    }

    /**
     * Convert wind degrees to cardinal direction
     */
    private function getWindDirection(float $degrees): string
    {
        $cardinals = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW', 'N'];
        return $cardinals[round($degrees % 360 / 45)];
    }
}
