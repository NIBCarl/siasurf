<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingNoShowNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Booking $booking,
        public float $retainedLessonPrice = 0.0,
    ) {
        $this->booking = $booking->loadMissing(['instructor', 'surfSpot']);
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ref = '#SURF-' . str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT);

        $mail = (new MailMessage)
            ->subject("Booking Marked as No-Show ({$ref})")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("Your surf lesson ({$ref}) on {$this->booking->date?->format('M d, Y')} was marked as a no-show because you did not arrive and did not cancel beforehand.")
            ->line('Per SiaSurf policy, no refund is issued for missed sessions.');

        if ($this->retainedLessonPrice > 0) {
            $mail->line('The lesson price of ₱' . number_format($this->retainedLessonPrice, 2) . ' has been credited to your instructor.');
        }

        return $mail->action('View Booking', route('student.bookings.show', $this->booking->id));
    }

    public function toArray(object $notifiable): array
    {
        $ref = '#SURF-' . str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT);

        return [
            'booking_id' => $this->booking->id,
            'retained_amount' => $this->retainedLessonPrice,
            'message' => "Booking {$ref} was marked as no-show. No refund applies.",
            'type' => 'booking_no_show',
            'url' => route('student.bookings.show', $this->booking->id),
        ];
    }
}
