<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingAwaitingPayment implements ShouldBroadcast
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
        return 'booking.awaiting_payment';
    }

    public function broadcastWith(): array
    {
        return [
            'booking' => [
                'id' => $this->booking->id,
                'date' => $this->booking->date instanceof \DateTimeInterface ? $this->booking->date->format('Y-m-d') : $this->booking->date,
                'time_period' => $this->booking->time_period instanceof \BackedEnum ? $this->booking->time_period->value : $this->booking->time_period,
                'status' => $this->booking->status instanceof \BackedEnum ? $this->booking->status->value : $this->booking->status,
                'payment_deadline_at' => $this->booking->payment_deadline_at instanceof \DateTimeInterface ? $this->booking->payment_deadline_at->toIso8601String() : $this->booking->payment_deadline_at,
            ],
            'instructor' => [
                'id' => $this->booking->instructor->id,
                'name' => $this->booking->instructor->name,
            ],
            'message' => "Your booking has been accepted! Please complete the payment.",
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
