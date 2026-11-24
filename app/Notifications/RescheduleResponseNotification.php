<?php

namespace App\Notifications;

use App\Models\BookingChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RescheduleResponseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public BookingChangeRequest $changeRequest,
        public bool $agreed,
        public bool $awaitingAdmin = false,
    ) {
        $this->changeRequest = $changeRequest->loadMissing(['booking.student', 'booking.instructor', 'booking.surfSpot']);
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->changeRequest->booking;
        $ref = '#SURF-' . str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT);
        $studentName = $booking->student?->name ?? 'The student';
        $newDate = $this->changeRequest->new_date?->format('M d, Y') ?? '';
        $newRange = $this->changeRequest->describeTimeRange(
            $this->changeRequest->new_start_time,
            $this->changeRequest->new_end_time,
            $this->changeRequest->new_time_period
        );

        if (! $this->agreed) {
            return (new MailMessage)
                ->subject("Reschedule Declined ({$ref})")
                ->greeting('Hello ' . $notifiable->name . ',')
                ->line("{$studentName} declined the reschedule to {$newDate} ({$newRange}).")
                ->line('The booking keeps its original schedule.')
                ->action('View Lesson', route('instructor.bookings.show', $booking->id));
        }

        if ($this->awaitingAdmin) {
            return (new MailMessage)
                ->subject("Student Agreed to Reschedule ({$ref}) — Awaiting SISA Approval")
                ->greeting('Hello ' . $notifiable->name . ',')
                ->line("{$studentName} agreed to reschedule {$ref} to {$newDate} ({$newRange}).")
                ->line('Because the original lesson starts in under 24 hours, SISA Admin must finalize approval before the slot moves.')
                ->action('View Lesson', route('instructor.bookings.show', $booking->id));
        }

        return (new MailMessage)
            ->subject("Reschedule Confirmed ({$ref})")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("{$studentName} agreed and {$ref} has been rescheduled to {$newDate} ({$newRange}).")
            ->action('View Lesson', route('instructor.bookings.show', $booking->id));
    }

    public function toArray(object $notifiable): array
    {
        $booking = $this->changeRequest->booking;
        $ref = '#SURF-' . str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT);
        $studentName = $booking->student?->name ?? 'The student';
        $newDate = $this->changeRequest->new_date?->format('M d, Y') ?? '';

        $message = match (true) {
            ! $this->agreed => "{$studentName} declined rescheduling {$ref} to {$newDate}. Original schedule kept.",
            $this->awaitingAdmin => "{$studentName} agreed to reschedule {$ref} to {$newDate}. Awaiting SISA approval.",
            default => "{$studentName} agreed — {$ref} is now rescheduled to {$newDate}.",
        };

        return [
            'booking_id' => $booking->id,
            'change_request_id' => $this->changeRequest->id,
            'agreed' => $this->agreed,
            'awaiting_admin' => $this->awaitingAdmin,
            'message' => $message,
            'type' => 'reschedule_response',
            'url' => route('instructor.bookings.show', $booking->id),
        ];
    }
}
