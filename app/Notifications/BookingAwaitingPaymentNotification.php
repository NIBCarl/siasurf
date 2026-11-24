<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Concerns\ResolvesNotificationUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The instructor accepted the request and the student now owes payment inside
 * the 15-minute checkout hold. Previously broadcast-only (BookingAwaitingPayment).
 */
class BookingAwaitingPaymentNotification extends Notification implements ShouldQueue
{
    use Queueable, ResolvesNotificationUrl;

    public function __construct(protected Booking $booking)
    {
        $this->booking = $booking->loadMissing(['student', 'instructor', 'surfSpot']);
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
        $reference = '#SURF-'.str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT);

        return (new MailMessage)
            ->subject("Complete Payment ({$reference})")
            ->greeting('Hello '.$notifiable->name.',')
            ->line("Your instructor {$this->booking->instructor->name} accepted your surf lesson ({$reference}).")
            ->line('- Date: '.$this->booking->date->format('M d, Y'))
            ->line('- Time: '.$this->booking->time_period->label())
            ->line('Please complete payment before the checkout hold expires, or the slot is released.')
            ->action('Complete Payment', route('student.bookings.show', $this->booking->id));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking_awaiting_payment',
            'booking_id' => $this->booking->id,
            'message' => "Your booking with {$this->booking->instructor->name} was accepted. Please complete payment.",
            'url' => $this->bookingUrl($notifiable, $this->booking->id),
        ];
    }
}
