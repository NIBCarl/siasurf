<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Booking $booking;

    /**
     * Create a new event instance.
     */
    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('instructor.' . $this->booking->instructor_id),
            new PrivateChannel('admin.notifications'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'booking.created';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'booking' => [
                'id' => $this->booking->id,
                'date' => $this->booking->date->format('Y-m-d'),
                'time_period' => $this->booking->time_period->value,
                'skill_level' => $this->booking->skill_level->value,
                'student_count' => $this->booking->student_count,
                'total_amount' => $this->booking->total_amount,
                'status' => $this->booking->status->value,
            ],
            'student' => [
                'id' => $this->booking->student->id,
                'name' => $this->booking->student->name,
            ],
            'instructor' => [
                'id' => $this->booking->instructor->id,
                'name' => $this->booking->instructor->name,
            ],
            'surf_spot' => [
                'id' => $this->booking->surfSpot->id,
                'name' => $this->booking->surfSpot->name,
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
