<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Concerns\ResolvesNotificationUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GcashReceiptRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable, ResolvesNotificationUrl;

    public function __construct(
        protected Booking $booking,
        protected string $reason,
    ) {
        $this->booking = $booking->loadMissing(['student', 'instructor', 'payment']);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ref = '#SURF-'.str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT);

        return (new MailMessage)
            ->subject("GCash Receipt Rejected ({$ref}) — Re-upload Required")
            ->greeting('Hello '.$notifiable->name.',')
            ->line("Your uploaded GCash receipt for booking {$ref} could not be verified.")
            ->line('Reason: '.$this->reason)
            ->line('A fresh payment window has been opened so you can upload a corrected screenshot before it expires.')
            ->action('Upload New Receipt', route('student.bookings.show', $this->booking->id));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $ref = '#SURF-'.str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT);

        return [
            'type' => 'gcash_receipt_rejected',
            'booking_id' => $this->booking->id,
            'reason' => $this->reason,
            'message' => "Your GCash receipt for {$ref} was rejected: {$this->reason}. Please upload a new receipt before the timer expires.",
            'url' => route('student.bookings.show', $this->booking->id),
        ];
    }
}
