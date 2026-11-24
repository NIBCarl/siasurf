<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingSpotChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Booking $booking,
        public string $previousSpotName,
        public string $newSpotName,
        public string $reason,
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

        return (new MailMessage)
            ->subject("Surf Spot Updated ({$ref}) — {$this->newSpotName}")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("Your instructor {$this->booking->instructor->name} updated the surf location for your upcoming lesson ({$ref}).")
            ->line("Schedule: {$dateLabel} ({$timeRange})")
            ->line("Previous Surf Spot: {$this->previousSpotName}")
            ->line("New Surf Spot: {$this->newSpotName}")
            ->line("Reason from instructor: {$this->reason}")
            ->action('View Booking Details', route('student.bookings.show', $this->booking->id));
    }

    public function toArray(object $notifiable): array
    {
        $ref = '#SURF-' . str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT);

        return [
            'booking_id' => $this->booking->id,
            'previous_spot' => $this->previousSpotName,
            'new_spot' => $this->newSpotName,
            'reason' => $this->reason,
            'message' => "Booking {$ref} surf spot changed from {$this->previousSpotName} to {$this->newSpotName}: {$this->reason}",
            'type' => 'booking_spot_changed',
            'url' => route('student.bookings.show', $this->booking->id),
        ];
    }
}
