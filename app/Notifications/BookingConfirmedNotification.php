<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Concerns\ResolvesNotificationUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The booking is confirmed and the lesson is locked in. Previously
 * broadcast-only (BookingConfirmed), so no history row was ever written.
 */
class BookingConfirmedNotification extends Notification implements ShouldQueue
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
        $isStudent = $notifiable->id === $this->booking->student_id;

        $mail = (new MailMessage)
            ->subject("Booking Confirmed ({$reference})")
            ->greeting('Hello '.$notifiable->name.',')
            ->line($isStudent
                ? "Your surf lesson with {$this->booking->instructor->name} is confirmed."
                : "Your lesson with {$this->booking->student->name} is confirmed.")
            ->line('- Date: '.$this->booking->date->format('M d, Y'))
            ->line('- Time: '.$this->booking->time_period->label())
            ->line('- Surf Spot: '.($this->booking->surfSpot?->name ?? 'To be confirmed'));

        return $mail->action('View Booking', $this->bookingUrl($notifiable, $this->booking->id)
            ?? route('student.bookings.show', $this->booking->id));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $isStudent = $notifiable->id === $this->booking->student_id;

        return [
            'type' => 'booking_confirmed',
            'booking_id' => $this->booking->id,
            'message' => $isStudent
                ? "Your booking with {$this->booking->instructor->name} is confirmed for "
                    .$this->booking->date->format('M j').'.'
                : "Your lesson with {$this->booking->student->name} is confirmed for "
                    .$this->booking->date->format('M j').'.',
            'url' => $this->bookingUrl($notifiable, $this->booking->id),
        ];
    }
}
