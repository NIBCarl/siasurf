<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InstructorReactivated extends Notification implements ShouldQueue
{
    use Queueable;

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
            ->subject('Your SiaSurf Instructor Account Has Been Reactivated')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Good news! Your instructor account has been reactivated.')
            ->line('You can now resume accepting bookings from students.')
            ->line('Please ensure you follow all safety guidelines to maintain good standing.')
            ->action('View Dashboard', url('/instructor/dashboard'))
            ->line('Thank you for your commitment to safe surfing instruction.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'account_reactivated',
            'message' => 'Your instructor account has been reactivated!',
        ];
    }
}