<?php

namespace App\Notifications;

use App\Models\BookingChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RescheduleProposedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public BookingChangeRequest $changeRequest)
    {
        $this->changeRequest = $changeRequest->loadMissing(['booking.instructor', 'booking.surfSpot', 'booking.student']);
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->changeRequest->booking;
        $ref = '#SURF-' . str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT);
        $oldDate = $this->changeRequest->original_date?->format('M d, Y') ?? '';
        $oldRange = $this->changeRequest->describeTimeRange(
            $this->changeRequest->original_start_time,
            $this->changeRequest->original_end_time,
            $this->changeRequest->original_time_period
        );
        $newDate = $this->changeRequest->new_date?->format('M d, Y') ?? '';
        $newRange = $this->changeRequest->describeTimeRange(
            $this->changeRequest->new_start_time,
            $this->changeRequest->new_end_time,
            $this->changeRequest->new_time_period
        );

        $mail = (new MailMessage)
            ->subject("Reschedule Request ({$ref}) — Your Agreement Is Required")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("Your instructor {$booking->instructor?->name} requested to reschedule your surf lesson ({$ref}).")
            ->line("Current Schedule: {$oldDate} ({$oldRange})")
            ->line("Proposed Schedule: {$newDate} ({$newRange})")
            ->line('Surf Spot: ' . ($booking->surfSpot?->name ?? 'Assigned break'));

        if ($this->changeRequest->reason) {
            $mail->line('Reason (also sent to your booking chat): ' . $this->changeRequest->reason);
        }

        return $mail
            ->line('Your schedule will only move once you agree. You can also message your instructor in SiaSurf or decline to keep your original schedule / cancel for a 100% lesson refund.')
            ->action('Respond to Reschedule', route('student.bookings.show', $booking->id));
    }

    public function toArray(object $notifiable): array
    {
        $booking = $this->changeRequest->booking;
        $ref = '#SURF-' . str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT);
        $newDate = $this->changeRequest->new_date?->format('M d, Y') ?? '';
        $newRange = $this->changeRequest->describeTimeRange(
            $this->changeRequest->new_start_time,
            $this->changeRequest->new_end_time,
            $this->changeRequest->new_time_period
        );

        return [
            'booking_id' => $booking->id,
            'change_request_id' => $this->changeRequest->id,
            'proposed_date' => $this->changeRequest->new_date?->format('Y-m-d'),
            'reason' => $this->changeRequest->reason,
            'message' => "Instructor requested to reschedule {$ref} to {$newDate} ({$newRange}). Please agree or decline.",
            'type' => 'reschedule_proposed',
            'url' => route('student.bookings.show', $booking->id),
        ];
    }
}
