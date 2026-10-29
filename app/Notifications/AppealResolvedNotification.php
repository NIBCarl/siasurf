<?php

namespace App\Notifications;

use App\Models\InstructorAppeal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppealResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected InstructorAppeal $appeal)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approved = $this->appeal->status === \App\Enums\AppealStatus::Approved;

        return (new MailMessage)
            ->subject($approved ? 'Your suspension appeal was approved' : 'Your suspension appeal was rejected')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($approved
                ? 'SISA approved your appeal. Your suspension has been lifted and your account is active again.'
                : 'SISA reviewed your appeal and upheld the suspension.')
            ->line('SISA response: ' . ($this->appeal->admin_response ?? 'No response recorded.'))
            ->action('View Account Status', route('instructor.suspended'))
            ->line('SiaSurf Safety Monitoring');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'appeal_resolved',
            'appeal_id' => $this->appeal->id,
            'status' => $this->appeal->status?->value,
            'message' => 'Your suspension appeal has been decided.',
        ];
    }
}
