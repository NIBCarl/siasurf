<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * FR 1.9.3 - the instructor declined the request, or the 2 hour window lapsed
 * without an answer.
 */
class BookingDeclined implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Booking $booking;
    public ?string $reason;
    public bool $expired;

    public function __construct(Booking $booking, ?string $reason = null, bool $expired = false)
    {
        $this->booking = $booking;
        $this->reason = $reason;
        $this->expired = $expired;
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
        return 'booking.declined';
    }

    public function broadcastWith(): array
    {
        $instructorName = $this->booking->instructor?->name ?? 'The instructor';
        $studentName = $this->booking->student?->name ?? 'The student';

        return [
            'booking' => [
                'id' => $this->booking->id,
                'date' => $this->booking->date instanceof \DateTimeInterface
                    ? $this->booking->date->format('Y-m-d')
                    : (string) $this->booking->date,
                'time_period' => $this->booking->time_period instanceof \BackedEnum
                    ? $this->booking->time_period->value
                    : (string) $this->booking->time_period,
                'status' => $this->booking->status instanceof \BackedEnum
                    ? $this->booking->status->value
                    : (string) $this->booking->status,
            ],
            'instructor' => [
                'id' => $this->booking->instructor_id,
                'name' => $instructorName,
            ],
            'student' => [
                'id' => $this->booking->student_id,
                'name' => $studentName,
            ],
            'expired' => $this->expired,
            'reason' => $this->reason,
            'message' => $this->expired
                ? "Booking request from {$studentName} expired - no response within 2 hours."
                : "{$instructorName} declined the booking request.",
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
