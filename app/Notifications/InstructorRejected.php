<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InstructorRejected extends Notification implements ShouldQueue
{
    use Queueable;

    protected $reason;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $reason)
    {
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
        return (new MailMessage)
            ->subject('SiaSurf Instructor Application Update')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Thank you for your interest in becoming a SiaSurf instructor.')
            ->line('After reviewing your application, we regret to inform you that we cannot approve your instructor account at this time.')
            ->line('Reason: ' . $this->reason)
            ->line('If you have any questions or would like to discuss this further, please contact SISA admin.')
            ->line('Thank you for your understanding.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'instructor_rejected',
            'reason' => $this->reason,
            'message' => 'Your instructor application was not approved.',
        ];
    }
}