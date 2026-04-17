<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingCancelled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Booking $booking;
    public ?string $reason;

    public function __construct(Booking $booking, ?string $reason = null)
    {
        $this->booking = $booking;
        $this->reason = $reason;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('student.' . $this->booking->student_id),
            new PrivateChannel('instructor.' . $this->booking->instructor_id),
            new PrivateChannel('admin.notifications'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'booking.cancelled';
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
            'cancelled_by' => [
                'id' => auth()->id(),
                'name' => auth()->user()?->name ?? 'System',
            ],
            'reason' => $this->reason,
            'message' => 'A booking has been cancelled.',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
