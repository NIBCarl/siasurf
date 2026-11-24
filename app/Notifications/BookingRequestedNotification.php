<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Concerns\ResolvesNotificationUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FR 1.9.1 - an instructor has a new booking request waiting for a decision.
 *
 * This previously existed only as a broadcast (BookingCreated), so the request
 * never reached the database-backed notification feed and the instructor's bell
 * stayed empty.
 */
class BookingRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable, ResolvesNotificationUrl;

    public function __construct(protected Booking $booking)
    {
        $this->booking = $booking->loadMissing(['student', 'surfSpot', 'instructor']);
    }

    /**
     * Database first: the bell should not wait on SMTP.
     *
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
            ->subject("New Booking Request ({$reference})")
            ->greeting('Hello '.$notifiable->name.',')
            ->line("{$this->booking->student->name} requested a surf lesson ({$reference}).")
            ->line('- Date: '.$this->booking->date->format('M d, Y'))
            ->line('- Time: '.$this->booking->time_period->label())
            ->line('- Surf Spot: '.($this->booking->surfSpot?->name ?? 'To be confirmed'))
            ->line('- Skill Level: '.$this->booking->skill_level->label())
            ->line('- Students: '.$this->booking->student_count)
            ->line('Please respond within 2 hours or the request releases automatically.')
            ->action('Review Request', route('instructor.bookings.show', $this->booking->id));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking_requested',
            'booking_id' => $this->booking->id,
            'student_id' => $this->booking->student_id,
            'message' => "New booking request from {$this->booking->student->name} for "
                .$this->booking->date->format('M j')
                .' at '.($this->booking->surfSpot?->name ?? 'your area').'.',
            'url' => $this->bookingUrl($notifiable, $this->booking->id),
        ];
    }
}
