<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StudentAccountStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected bool $banned) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->banned) {
            return (new MailMessage)
                ->subject('SiaSurf Account Restricted')
                ->greeting('Hello '.$notifiable->name.',')
                ->line('Your SiaSurf student account has been restricted by SISA administration.')
                ->line('Please contact SISA support if you believe this was done in error.');
        }

        return (new MailMessage)
            ->subject('SiaSurf Account Reinstated')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your SiaSurf student account has been reinstated by SISA administration.')
            ->action('Go to Dashboard', route('student.dashboard'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->banned ? 'student_account_banned' : 'student_account_unbanned',
            'message' => $this->banned
                ? 'Your SiaSurf student account has been restricted by SISA administration.'
                : 'Your SiaSurf student account has been reinstated by SISA administration.',
            'url' => route('student.dashboard'),
        ];
    }
}
