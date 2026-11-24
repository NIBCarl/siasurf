<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SessionCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Booking $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking->loadMissing(['instructor', 'surfSpot']);
    }

    /**
     * Database-only so we do not double-email when SendReviewRequest runs later.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $ref = '#SURF-'.str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT);
        $instructorName = $this->booking->instructor?->name ?? 'your instructor';

        return [
            'type' => 'session_completed',
            'booking_id' => $this->booking->id,
            'instructor_id' => $this->booking->instructor_id,
            'message' => "Your surf lesson ({$ref}) with {$instructorName} is complete. View your session or leave feedback.",
            'url' => route('student.bookings.show', $this->booking->id),
        ];
    }
}
