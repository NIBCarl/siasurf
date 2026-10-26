<?php

namespace App\Notifications;

use App\Models\BookingChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FR 1.9.4 - a late cancellation/reschedule landed in the admin approval queue.
 */
class BookingChangeRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected BookingChangeRequest $changeRequest;

    public function __construct(BookingChangeRequest $changeRequest)
    {
        $this->changeRequest = $changeRequest;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->changeRequest->booking;

        return (new MailMessage)
            ->subject('Approval Needed: ' . $this->changeRequest->type->label() . ' Request')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('A booking change needs your decision because the lesson starts in less than 24 hours.')
            ->line('Request Details:')
            ->line('- Type: ' . $this->changeRequest->type->label())
            ->line('- Booking: #SURF-' . str_pad((string) $this->changeRequest->booking_id, 5, '0', STR_PAD_LEFT))
            ->line('- Requested By: ' . ($this->changeRequest->requestedBy?->name ?? 'System'))
            ->line('- Current Slot: ' . $this->changeRequest->describeOriginalSlot())
            ->line('- Requested Slot: ' . $this->changeRequest->describeNewSlot())
            ->line('- Reason: ' . ($this->changeRequest->reason ?? 'Not provided'))
            ->action('Review Request', route('admin.change-requests.index'))
            ->line('Please approve or reject this request as soon as possible.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'change_request_id' => $this->changeRequest->id,
            'booking_id' => $this->changeRequest->booking_id,
            'type' => 'booking_change_requested',
            'change_type' => $this->changeRequest->type->value,
            'message' => $this->changeRequest->type->label() . ' request for booking #' . $this->changeRequest->booking_id . ' needs admin approval.',
        ];
    }
}