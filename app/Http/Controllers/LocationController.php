<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Exceptions\SafetyViolationException;
use App\Models\Booking;
use App\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Real-time location endpoints.
 *
 * Instructor pings are only accepted while the lesson is actually running, so
 * the feature cannot be used to track somebody outside a session.
 */
class LocationController extends Controller
{
    public function __construct(
        protected LocationService $locationService
    ) {}

    /**
     * Instructor posts a GPS fix for a live session.
     */
    public function update(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->instructor_id !== $request->user()->id) {
            abort(403, 'Only the assigned instructor can share a location for this session.');
        }

        if ($booking->status !== BookingStatus::InProgress) {
            return response()->json([
                'message' => 'Location sharing is only available while the session is in progress.',
                'status' => $booking->status->value,
            ], 422);
        }

        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'speed_mps' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'heading' => ['nullable', 'numeric', 'min:0', 'max:360'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        try {
            $location = $this->locationService->record($booking, $validated);
        } catch (SafetyViolationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Location recorded.',
            'location' => $location->toMapPayload(),
            'distance_from_spot_m' => $location->distance_from_spot_m !== null
                ? (float) $location->distance_from_spot_m
                : null,
            'on_site' => $location->distance_from_spot_m !== null
                && (float) $location->distance_from_spot_m <= LocationService::ON_SITE_RADIUS_METERS,
        ]);
    }

    /**
     * Student (or the instructor, or SISA) reads the current live state.
     */
    public function show(Request $request, Booking $booking): JsonResponse
    {
        Gate::authorize('view', $booking);

        return response()->json([
            'live' => $this->locationService->liveStateForBooking($booking),
            'trail' => $this->locationService->trailForBooking($booking, 60)
                ->map(fn ($location) => $location->toMapPayload())
                ->values()
                ->all(),
            'on_site_radius_m' => LocationService::ON_SITE_RADIUS_METERS,
            'stale_after_seconds' => LocationService::STALE_AFTER_SECONDS,
        ]);
    }

    /**
     * SISA live map feed: every running lesson on Siargao right now.
     */
    public function liveSessions(): JsonResponse
    {
        return response()->json([
            'sessions' => $this->locationService->liveSessions(),
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'on_site_radius_m' => LocationService::ON_SITE_RADIUS_METERS,
                'stale_after_seconds' => LocationService::STALE_AFTER_SECONDS,
                'siargao_bounds' => LocationService::SIARGAO_BOUNDS,
            ],
        ]);
    }
}
