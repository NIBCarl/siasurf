<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\User;
use App\Notifications\Concerns\ResolvesNotificationUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GcashReceiptUploadedNotification extends Notification implements ShouldQueue
{
    use Queueable, ResolvesNotificationUrl;

    public function __construct(protected Booking $booking)
    {
        $this->booking = $booking->loadMissing(['student', 'instructor', 'payment']);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->isAdmin()
            ? ['database']
            : ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ref = '#SURF-'.str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT);
        $studentName = $this->booking->student?->name ?? 'A student';
        $referenceNumber = $this->booking->payment?->receipt_reference;

        $mail = (new MailMessage)
            ->subject("GCash Receipt Uploaded ({$ref})")
            ->greeting('Hello '.$notifiable->name.',')
            ->line("{$studentName} uploaded a GCash proof-of-payment receipt for booking {$ref}.");

        if ($referenceNumber) {
            $mail->line('- Reference No.: '.$referenceNumber);
        }

        return $mail
            ->line('Please verify the receipt so the booking can be confirmed.')
            ->action('Review Receipt', $this->bookingUrl($notifiable, $this->booking->id)
                ?? $this->dashboardUrl($notifiable));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $ref = '#SURF-'.str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT);
        $studentName = $this->booking->student?->name ?? 'A student';

        return [
            'type' => 'gcash_receipt_uploaded',
            'booking_id' => $this->booking->id,
            'message' => "{$studentName} uploaded a GCash receipt for {$ref}. Please verify to confirm the booking.",
            'url' => $this->bookingUrl($notifiable, $this->booking->id),
        ];
    }
}
