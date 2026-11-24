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
            ->subject("Rescheduled to {$dateLabel} ({$ref})")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("Your surf lesson ({$ref}) with {$this->booking->instructor->name} has been rescheduled to {$dateLabel} ({$timeRange}).")
            ->line('Surf Spot: ' . ($this->booking->surfSpot?->name ?? 'Assigned break'));

        if ($this->reason) {
            $mail->line('Reason: ' . $this->reason);
        }

        if ($this->adminNote) {
            $mail->line('SISA Note: ' . $this->adminNote);
        }

        return $mail
            ->action('View Rescheduled Booking', route('student.bookings.show', $this->booking->id));
    }

    public function toArray(object $notifiable): array
    {
        $ref = '#SURF-' . str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT);
        $dateLabel = $this->booking->date?->format('M d, Y') ?? '';
        $timeRange = ($this->booking->start_time && $this->booking->end_time)
            ? $this->booking->start_time->format('g:i A') . ' – ' . $this->booking->end_time->format('g:i A')
            : ucfirst((string) $this->booking->time_period?->value);

        return [
            'booking_id' => $this->booking->id,
            'new_date' => $this->booking->date?->format('Y-m-d'),
            'reason' => $this->reason,
            'admin_note' => $this->adminNote,
            'message' => "Booking {$ref} has been rescheduled to {$dateLabel} ({$timeRange}).",
            'type' => 'booking_rescheduled',
            'url' => route('student.bookings.show', $this->booking->id),
        ];
    }
}
