<?php

namespace App\Events;

use App\Models\Booking;
use App\Models\SessionLocation;
use App\Services\LocationService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A live session pinged a new position.
 *
 * Emitted at most every LocationService::BROADCAST_THROTTLE_SECONDS, so a
 * 5-second GPS watch does not flood the admin dashboard.
 */
class SessionLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Booking $booking,
        public SessionLocation $location
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('student.'.$this->booking->student_id),
            new PrivateChannel('instructor.'.$this->booking->instructor_id),
            new PrivateChannel('admin.notifications'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'session.location.updated';
    }

    public function broadcastWith(): array
    {
        $spot = $this->booking->surfSpot;
        $distance = $this->location->distance_from_spot_m !== null
            ? (float) $this->location->distance_from_spot_m
            : null;

        return [
            'booking_id' => $this->booking->id,
            'booking_reference' => 'SURF-'.str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT),
            'instructor' => [
                'id' => $this->booking->instructor_id,
                'name' => $this->booking->instructor?->name,
            ],
            'student' => [
                'id' => $this->booking->student_id,
                'name' => $this->booking->student?->name,
            ],
            'spot' => $spot ? [
                'id' => $spot->id,
                'name' => $spot->name,
                'area' => $spot->area,
                'latitude' => $spot->latitude !== null ? (float) $spot->latitude : null,
                'longitude' => $spot->longitude !== null ? (float) $spot->longitude : null,
            ] : null,
            'location' => $this->location->toMapPayload(),
            'distance_from_spot_m' => $distance,
            'on_site' => $distance !== null
                && $distance <= LocationService::ON_SITE_RADIUS_METERS,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
