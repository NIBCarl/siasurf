<?php

namespace App\Events;

use App\Models\BookingChangeRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * FR 1.9.4 - an admin approved or rejected a late cancellation/reschedule.
 */
class BookingChangeResolved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public BookingChangeRequest $changeRequest;

    public function __construct(BookingChangeRequest $changeRequest)
    {
        $this->changeRequest = $changeRequest;
    }

    public function broadcastOn(): array
    {
        $booking = $this->changeRequest->booking;

        $channels = [new PrivateChannel('admin.notifications')];

        if ($booking) {
            $channels[] = new PrivateChannel('student.' . $booking->student_id);
            $channels[] = new PrivateChannel('instructor.' . $booking->instructor_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'booking.change_resolved';
    }

    public function broadcastWith(): array
    {
        $booking = $this->changeRequest->booking;
        $status = $this->changeRequest->status->label();
        $type = $this->changeRequest->type->label();

        return [
            'change_request' => [
                'id' => $this->changeRequest->id,
                'booking_id' => $this->changeRequest->booking_id,
                'type' => $this->changeRequest->type->value,
                'status' => $this->changeRequest->status->value,
                'status_label' => $status,
                'original_slot' => $this->changeRequest->describeOriginalSlot(),
                'new_slot' => $this->changeRequest->describeNewSlot(),
                'admin_note' => $this->changeRequest->admin_note,
            ],
            'booking' => $booking ? [
                'id' => $booking->id,
                'date' => $booking->date instanceof \DateTimeInterface
                    ? $booking->date->format('Y-m-d')
                    : (string) $booking->date,
                'status' => $booking->status instanceof \BackedEnum
                    ? $booking->status->value
                    : (string) $booking->status,
            ] : null,
            'message' => "Your {$type} request for booking #{$this->changeRequest->booking_id} was {$status}.",
            'timestamp' => now()->toIso8601String(),
        ];
    }
}