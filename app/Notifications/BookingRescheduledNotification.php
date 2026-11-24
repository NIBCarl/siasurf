<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingRescheduledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Booking $booking,
        public ?string $reason = null,
        public ?string $adminNote = null,
    ) {
        $this->booking = $booking->loadMissing(['instructor', 'surfSpot', 'student']);
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ref = '#SURF-' . str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT);
        $dateLabel = $this->booking->date?->format('M d, Y') ?? '';
        $timeRange = ($this->booking->start_time && $this->booking->end_time)
            ? $this->booking->start_time->format('g:i A') . ' – ' . $this->booking->end_time->format('g:i A')
            : ucfirst((string) $this->booking->time_period?->value);

        $mail = (new MailMessage)
            ->subject("Lesson Rescheduled ({$ref}) — Confirm or Cancel for Full Refund")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("Your instructor {$this->booking->instructor->name} rescheduled your surf lesson ({$ref}).")
            ->line("New Schedule: {$dateLabel} ({$timeRange})")
            ->line('Surf Spot: ' . ($this->booking->surfSpot?->name ?? 'Assigned break'));

        if ($this->reason) {
            $mail->line('Reason: ' . $this->reason);
        }

        if ($this->adminNote) {
            $mail->line('SISA Note: ' . $this->adminNote);
        }

        return $mail
            ->line('If this new schedule does not work for you, you can decline and cancel for a 100% refund of the lesson price (7% service fee retained).')
            ->action('Review New Date', route('student.bookings.show', $this->booking->id));
    }

    public function toArray(object $notifiable): array
    {
        $ref = '#SURF-' . str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT);
        $dateLabel = $this->booking->date?->format('M d, Y') ?? '';

        return [
            'booking_id' => $this->booking->id,
            'new_date' => $this->booking->date?->format('Y-m-d'),
            'reason' => $this->reason,
            'admin_note' => $this->adminNote,
            'message' => "Booking {$ref} was rescheduled to {$dateLabel}. Accept the new date or cancel for a full lesson refund.",
            'type' => 'booking_rescheduled',
        ];
    }
}
