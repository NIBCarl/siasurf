<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingConfirmed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Booking $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('student.' . $this->booking->student_id),
            new PrivateChannel('instructor.' . $this->booking->instructor_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'booking.confirmed';
    }

    public function broadcastWith(): array
    {
        return [
            'booking' => [
                'id' => $this->booking->id,
                'date' => $this->booking->date->format('Y-m-d'),
                'time_period' => $this->booking->time_period->value,
                'status' => $this->booking->status->value,
            ],
            'instructor' => [
                'id' => $this->booking->instructor->id,
                'name' => $this->booking->instructor->name,
                'phone' => $this->booking->instructor->phone,
            ],
            'message' => "Your booking with {$this->booking->instructor->name} has been confirmed!",
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
