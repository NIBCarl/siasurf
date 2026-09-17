<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Booking $booking;
    protected ?string $reason;

    /**
     * Create a new notification instance.
     */
    public function __construct(Booking $booking, ?string $reason = null)
    {
        $this->booking = $booking;
        $this->reason = $reason;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $roleMessage = $notifiable->id === $this->booking->student_id
            ? "Your booking with instructor {$this->booking->instructor->name} has been cancelled."
            : "The booking by student {$this->booking->student->name} has been cancelled.";

        return (new MailMessage)
            ->subject('Surf Lesson Booking Cancelled')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($roleMessage)
            ->line('Booking Details:')
            ->line('- Date: ' . ($this->booking->date instanceof \DateTimeInterface ? $this->booking->date->format('Y-m-d') : $this->booking->date))
            ->line('- Time Period: ' . ($this->booking->time_period instanceof \BackedEnum ? $this->booking->time_period->label() : $this->booking->time_period))
            ->line('- Spot: ' . $this->booking->surfSpot->name)
            ->line('- Reason: ' . ($this->reason ?? 'Payment window expired.'))
            ->action('View Booking', route('student.bookings.show', $this->booking->id))
            ->line('If you have any questions, please contact support.');
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
            'message' => 'Booking was cancelled: ' . ($this->reason ?? 'Payment window expired.'),
            'reason' => $this->reason,
            'type' => 'booking_cancelled',
        ];
    }
}
