<?php

namespace App\Notifications;

use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ReviewReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Review $review)
    {
        $this->review = $review->loadMissing(['student', 'booking']);
    }

    /**
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
        $studentName = $this->review->student?->name ?? 'A student';
        $ratingPart = $this->review->rating !== null
            ? " ({$this->review->rating}/5)"
            : '';

        return [
            'type' => 'review_received',
            'review_id' => $this->review->id,
            'booking_id' => $this->review->booking_id,
            'rating' => $this->review->rating,
            'message' => "{$studentName} left a review{$ratingPart} for your surf lesson.",
            'url' => $this->review->booking_id
                ? route('instructor.bookings.show', $this->review->booking_id)
                : route('instructor.dashboard'),
        ];
    }
}
