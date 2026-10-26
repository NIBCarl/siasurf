<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Events\SessionLocationUpdated;
use App\Exceptions\SafetyViolationException;
use App\Models\Booking;
use App\Models\SessionLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Real-time location for live surf sessions.
 *
 * Design notes:
 * - Every ping is appended to `session_locations`, never overwritten, so the
 *   trail survives a dropped socket and stays auditable by SISA.
 * - Coordinates are validated against Siargao's bounding box before storage.
 *   A "0,0" or Manila fix from a mis-configured device would otherwise look
 *   like an instructor 800 km offshore.
 * - Broadcasts are throttled: a 5-second GPS watch would otherwise emit a
 *   WebSocket frame every 5 seconds to every admin dashboard.
 */
class LocationService
{
    /**
     * Bounding box that comfortably contains Siargao Island. A fix outside this
     * box is rejected, because it is far more likely to be a device/emulator
     * artefact than a real position.
     */
    public const SIARGAO_BOUNDS = [
        'min_lat' => 9.60,
        'max_lat' => 10.10,
        'min_lng' => 125.95,
        'max_lng' => 126.30,
    ];

    /**
     * Pings closer together than this are coalesced: the newest is stored but no
     * broadcast is emitted. Keeps the socket quiet during a long session.
     */
    public const BROADCAST_THROTTLE_SECONDS = 15;

    /**
     * A fix older than this is not shown as the instructor's live position.
     */
    public const STALE_AFTER_SECONDS = 120;

    /**
     * Radius used to decide a session is actually running at the booked break.
     */
    public const ON_SITE_RADIUS_METERS = 400.0;

    /**
     * Store a location ping for a live session and broadcast it to the student
     * and the admin channel when it is due.
     *
     * @param  array{latitude: float|string, longitude: float|string, accuracy_m?: float|string|null, speed_mps?: float|string|null, heading?: float|string|null, recorded_at?: string|null, source?: string|null}  $payload
     *
     * @throws SafetyViolationException when the coordinates are unusable.
     */
    public function record(Booking $booking, array $payload): SessionLocation
    {
        $latitude = (float) $payload['latitude'];
        $longitude = (float) $payload['longitude'];

        $this->assertUsableCoordinates($latitude, $longitude);

        $booking->loadMissing('surfSpot');

        $spot = $booking->surfSpot;
        $distance = $spot ? $spot->distanceFrom($latitude, $longitude) : null;

        $recordedAt = isset($payload['recorded_at'])
            ? Carbon::parse($payload['recorded_at'])
            : now();

        $location = SessionLocation::create([
            'booking_id' => $booking->id,
            'instructor_id' => $booking->instructor_id,
            'latitude' => round($latitude, 7),
            'longitude' => round($longitude, 7),
            'accuracy_m' => $this->nullableFloat($payload['accuracy_m'] ?? null),
            'speed_mps' => $this->nullableFloat($payload['speed_mps'] ?? null),
            'heading' => $this->nullableFloat($payload['heading'] ?? null),
            'distance_from_spot_m' => $distance !== null ? round($distance, 2) : null,
            'recorded_at' => $recordedAt,
            'source' => $payload['source'] ?? 'gps',
        ]);

        if ($this->shouldBroadcast($booking, $location)) {
            $this->broadcast($booking, $location);
        }

        return $location;
    }

    /**
     * Reject coordinates that cannot belong to a Siargao surf lesson.
     *
     * @throws SafetyViolationException
     */
    public function assertUsableCoordinates(float $latitude, float $longitude): void
    {
        if (! is_finite($latitude) || ! is_finite($longitude)) {
            throw new SafetyViolationException('Location could not be read from the device.');
        }

        if (abs($latitude) < 0.0001 && abs($longitude) < 0.0001) {
            throw new SafetyViolationException('The device reported a null-island fix (0, 0). Enable precise location and try again.');
        }

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new SafetyViolationException('The reported coordinates are outside the valid range.');
        }

        if (! $this->isWithinSiargao($latitude, $longitude)) {
            throw new SafetyViolationException(
                'That position is outside Siargao Island. Live tracking only accepts pings from the island.'
            );
        }
    }

    public function isWithinSiargao(float $latitude, float $longitude): bool
    {
        return $latitude >= self::SIARGAO_BOUNDS['min_lat']
            && $latitude <= self::SIARGAO_BOUNDS['max_lat']
            && $longitude >= self::SIARGAO_BOUNDS['min_lng']
            && $longitude <= self::SIARGAO_BOUNDS['max_lng'];
    }

    /**
     * The newest ping for a booking, or null when the instructor never shared.
     */
    public function latestForBooking(Booking $booking): ?SessionLocation
    {
        return SessionLocation::query()
            ->where('booking_id', $booking->id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Full trail for a booking, oldest first, for the admin audit view.
     *
     * @return Collection<int, SessionLocation>
     */
    public function trailForBooking(Booking $booking, int $limit = 200): Collection
    {
        return SessionLocation::query()
            ->where('booking_id', $booking->id)
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * A single payload describing the live state of one booking, or null when
     * there is nothing worth showing.
     */
    public function liveStateForBooking(Booking $booking): ?array
    {
        $booking->loadMissing(['surfSpot', 'instructor', 'student']);

        $latest = $this->latestForBooking($booking);

        if (! $latest) {
            return null;
        }

        $spot = $booking->surfSpot;
        $distance = $latest->distance_from_spot_m !== null
            ? (float) $latest->distance_from_spot_m
            : ($spot ? $spot->distanceFrom((float) $latest->latitude, (float) $latest->longitude) : null);

        return [
            'booking_id' => $booking->id,
            'booking_reference' => 'SURF-'.str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT),
            'status' => $booking->status->value,
            'status_label' => $booking->status->label(),
            'instructor' => $this->instructorPayload($booking),
            'student' => $this->studentPayload($booking),
            'spot' => $spot ? $spot->toMapPayload() : null,
            'location' => $latest->toMapPayload(),
            'distance_from_spot_m' => $distance !== null ? round($distance, 1) : null,
            'on_site' => $distance !== null && $distance <= self::ON_SITE_RADIUS_METERS,
            'session_time_remaining' => $booking->getSessionTimeRemaining(),
        ];
    }

    /**
     * Everything SISA needs to watch running lessons on one map.
     *
     * @return array<int, array<string, mixed>>
     */
    public function liveSessions(): array
    {
        return Booking::query()
            ->with(['instructor', 'student', 'surfSpot'])
            ->whereIn('status', [BookingStatus::InProgress, BookingStatus::Confirmed])
            ->whereDate('date', today())
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (Booking $booking) => $this->liveStateForBooking($booking) ?? [
                // No ping yet: still surface the lesson so the map shows what is
                // scheduled even before the instructor goes live.
                'booking_id' => $booking->id,
                'booking_reference' => 'SURF-'.str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT),
                'status' => $booking->status->value,
                'status_label' => $booking->status->label(),
                'instructor' => $this->instructorPayload($booking),
                'student' => $this->studentPayload($booking),
                'spot' => $booking->surfSpot ? $booking->surfSpot->toMapPayload() : null,
                'location' => null,
                'distance_from_spot_m' => null,
                'on_site' => null,
                'session_time_remaining' => $booking->getSessionTimeRemaining(),
            ])
            ->values()
            ->all();
    }

    private function instructorPayload(Booking $booking): ?array
    {
        return $booking->instructor ? [
            'id' => $booking->instructor->id,
            'name' => $booking->instructor->name,
            'phone' => $booking->instructor->phone,
        ] : null;
    }

    private function studentPayload(Booking $booking): ?array
    {
        return $booking->student ? [
            'id' => $booking->student->id,
            'name' => $booking->student->name,
        ] : null;
    }

    /**
     * Only emit a socket frame when the previous ping is old enough, or when the
     * instructor has just moved onto/off the booked break.
     */
    private function shouldBroadcast(Booking $booking, SessionLocation $location): bool
    {
        $previous = SessionLocation::query()
            ->where('booking_id', $booking->id)
            ->where('id', '<', $location->id)
            ->orderByDesc('id')
            ->first();

        if (! $previous) {
            return true;
        }

        $secondsSince = $previous->recorded_at
            ? abs($previous->recorded_at->diffInSeconds($location->recorded_at))
            : self::BROADCAST_THROTTLE_SECONDS;

        if ($secondsSince >= self::BROADCAST_THROTTLE_SECONDS) {
            return true;
        }

        $radius = self::ON_SITE_RADIUS_METERS;
        $wasOnSite = $previous->distance_from_spot_m !== null
            && (float) $previous->distance_from_spot_m <= $radius;
        $isOnSite = $location->distance_from_spot_m !== null
            && (float) $location->distance_from_spot_m <= $radius;

        return $wasOnSite !== $isOnSite;
    }

    private function broadcast(Booking $booking, SessionLocation $location): void
    {
        try {
            broadcast(new SessionLocationUpdated($booking, $location))->toOthers();
        } catch (\Throwable $exception) {
            // A dead socket must never fail a location write.
            Log::warning('Failed to broadcast session location.', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Haversine distance in metres.
     */
    public static function haversineMeters(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadius = 6371000.0;

        $latFrom = deg2rad($lat1);
        $latTo = deg2rad($lat2);
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }
}
