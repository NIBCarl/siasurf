<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InstructorSuspended extends Notification
{
    use Queueable;

    protected $reason;
    protected $duration;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $reason, int $duration)
    {
        $this->reason = $reason;
        $this->duration = $duration;
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
        return (new MailMessage)
            ->subject('Your Instructor Account Has Been Suspended')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Your instructor account has been suspended for ' . $this->duration . ' days.')
            ->line('Reason for suspension: ' . $this->reason)
            ->line('During this period, you will not be able to accept new bookings or be featured in search results.')
            ->action('View History', url('/instructor/history'))
            ->line('If you believe this is an error, please contact SISA support.');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'instructor_suspended',
            'reason' => $this->reason,
            'duration' => $this->duration,
            'message' => 'Your instructor account has been suspended for ' . $this->duration . ' days.',
        ];
    }
}
