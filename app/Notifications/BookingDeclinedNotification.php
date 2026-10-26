<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FR 1.9.3 - tells the student (and the instructor) that a booking request was
 * declined or timed out.
 */
class BookingDeclinedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Booking $booking;
    protected bool $expired;

    public function __construct(Booking $booking, bool $expired = false)
    {
        $this->booking = $booking;
        $this->expired = $expired;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isStudent = $notifiable->id === $this->booking->student_id;

        $intro = $this->expired
            ? 'Your booking request expired because the instructor did not respond within the 2 hour window.'
            : ($isStudent
                ? "Your booking request was declined by instructor {$this->booking->instructor->name}."
                : 'The booking request was declined.');

        $mail = (new MailMessage)
            ->subject('Surf Lesson Booking ' . ($this->expired ? 'Expired' : 'Declined'))
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($intro)
            ->line('Booking Details:')
            ->line('- Reference: #SURF-' . str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT))
            ->line('- Date: ' . $this->booking->date->format('M d, Y'))
            ->line('- Time Period: ' . $this->booking->time_period->label());

        if (!$this->expired && $this->booking->decline_reason) {
            $mail->line('- Reason: ' . $this->booking->decline_reason);
        }

        if ($isStudent) {
            $mail->action('Find Another Instructor', route('instructors.search'))
                ->line('Your time slot has been released, so you can book again right away.');
        }

        return $mail->line('If you have any questions, please contact SISA support.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'type' => $this->expired ? 'booking_expired' : 'booking_declined',
            'expired' => $this->expired,
            'reason' => $this->booking->decline_reason,
            'message' => $this->expired
                ? 'Booking request expired - no instructor response within 2 hours.'
                : 'Booking request was declined: ' . ($this->booking->decline_reason ?? 'No reason given.'),
        ];
    }
}