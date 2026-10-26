<?php

namespace App\Events;

use App\Models\BookingChangeRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * FR 1.9.4 - a cancellation or reschedule was filed. Admins need to see it
 * immediately when it falls inside the 24 hour approval window.
 */
class BookingChangeRequested implements ShouldBroadcast
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
        return 'booking.change_requested';
    }

    public function broadcastWith(): array
    {
        $booking = $this->changeRequest->booking;
        $requester = $this->changeRequest->requestedBy;
        $type = $this->changeRequest->type->label();

        return [
            'change_request' => [
                'id' => $this->changeRequest->id,
                'booking_id' => $this->changeRequest->booking_id,
                'type' => $this->changeRequest->type->value,
                'status' => $this->changeRequest->status->value,
                'requires_admin_approval' => $this->changeRequest->requires_admin_approval,
                'original_slot' => $this->changeRequest->describeOriginalSlot(),
                'new_slot' => $this->changeRequest->describeNewSlot(),
                'reason' => $this->changeRequest->reason,
            ],
            'booking' => $booking ? [
                'id' => $booking->id,
                'date' => $booking->date instanceof \DateTimeInterface
                    ? $booking->date->format('Y-m-d')
                    : (string) $booking->date,
            ] : null,
            'requested_by' => [
                'id' => $requester?->id,
                'name' => $requester?->name ?? 'System',
            ],
            'message' => $this->changeRequest->requires_admin_approval
                ? "{$type} request for booking #{$this->changeRequest->booking_id} needs admin approval (lesson starts in under 24 hours)."
                : "{$type} request applied to booking #{$this->changeRequest->booking_id}.",
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
