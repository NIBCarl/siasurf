<?php

namespace App\Notifications;

use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ReviewResponseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Review $review)
    {
        $this->review = $review->loadMissing(['instructor', 'booking']);
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
        $instructorName = $this->review->instructor?->name ?? 'Your instructor';

        return [
            'type' => 'review_response',
            'review_id' => $this->review->id,
            'booking_id' => $this->review->booking_id,
            'message' => "{$instructorName} replied to your surf lesson review.",
            'url' => $this->review->booking_id
                ? route('student.bookings.show', $this->review->booking_id)
                : route('student.reviews.index'),
        ];
    }
}
