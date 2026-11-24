<?php

namespace App\Notifications;

use App\Enums\AttendanceStatus;
use App\Models\Booking;
use App\Notifications\Concerns\ResolvesNotificationUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Attendance was recorded after a session. Previously broadcast-only
 * (SessionAttendanceMarked), so the student had no record to return to.
 */
class SessionAttendanceNotification extends Notification implements ShouldQueue
{
    use Queueable, ResolvesNotificationUrl;

    public function __construct(
        protected Booking $booking,
        protected AttendanceStatus $attendance,
    ) {
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
        return (new MailMessage)
            ->subject('Session Attendance Recorded')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Attendance for your lesson on '.$this->booking->date->format('M d, Y').' was recorded as: '.$this->attendance->label().'.')
            ->action('View Booking', $this->bookingUrl($notifiable, $this->booking->id)
                ?? $this->dashboardUrl($notifiable));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'session_attendance',
            'booking_id' => $this->booking->id,
            'attendance' => $this->attendance->value,
            'message' => 'Attendance recorded for your '.$this->booking->date->format('M j').' lesson: '.$this->attendance->label().'.',
            'url' => $this->bookingUrl($notifiable, $this->booking->id),
        ];
    }
}
