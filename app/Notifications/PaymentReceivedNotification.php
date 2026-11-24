<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\ResolvesNotificationUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A payment landed. Previously broadcast-only (PaymentReceived), so the receipt
 * existed in memory for a second and then vanished.
 */
class PaymentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable, ResolvesNotificationUrl;

    public function __construct(protected Payment $payment)
    {
        $this->payment = $payment->loadMissing(['booking.student', 'booking.instructor', 'booking.surfSpot']);
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
        $booking = $this->payment->booking;
        $reference = $booking !== null
            ? '#SURF-'.str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT)
            : '#'.$this->payment->id;

        return (new MailMessage)
            ->subject("Payment Received ({$reference})")
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We received a payment of PHP '.number_format((float) $this->payment->amount, 2).' ('.$reference.').')
            ->line('- Method: '.$this->payment->payment_method->label())
            ->action('View Booking', $this->bookingUrl($notifiable, $booking?->id) ?? $this->dashboardUrl($notifiable));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_received',
            'payment_id' => $this->payment->id,
            'booking_id' => $this->payment->booking_id,
            'amount' => (float) $this->payment->amount,
            'message' => 'Payment of PHP '.number_format((float) $this->payment->amount, 2).' received.',
            'url' => $this->bookingUrl($notifiable, $this->payment->booking_id)
                ?? $this->dashboardUrl($notifiable),
        ];
    }
}
