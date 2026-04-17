<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SendReviewRequest extends Notification implements ShouldQueue
{
    use Queueable;

    protected Booking $booking;

    /**
     * Create a new notification instance.
     */
    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $instructorName = $this->booking->instructor->name;
        $reviewUrl = route('student.reviews.create', $this->booking->id);

        return (new MailMessage)
            ->subject('How was your surfing lesson with ' . $instructorName . '?')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('We hope you had an amazing time surfing with ' . $instructorName . ' earlier today.')
            ->line('Your feedback helps other students find great instructors and helps us improve our service.')
            ->action('Write a Review', $reviewUrl)
            ->line('It only takes a minute, and your honest opinion means a lot to our community.')
            ->line('Thank you for choosing SiaSurf!')
            ->salutation('Best regards,\nThe SiaSurf Team');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'instructor_id' => $this->booking->instructor_id,
            'instructor_name' => $this->booking->instructor->name,
            'type' => 'review_request',
        ];
    }
}
