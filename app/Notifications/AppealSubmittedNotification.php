<?php

namespace App\Notifications;

use App\Models\InstructorAppeal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppealSubmittedNotification extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject('Instructor suspension appeal awaiting review')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('An instructor filed an appeal against an active suspension.')
            ->line('Instructor: ' . ($this->appeal->instructor?->name ?? 'Unknown'))
            ->line('Suspension reason: ' . ($this->appeal->suspension?->reason ?? 'N/A'))
            ->action('Review Appeal', route('admin.appeals.show', $this->appeal))
            ->line('SiaSurf Safety Monitoring');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'appeal_submitted',
            'appeal_id' => $this->appeal->id,
            'instructor_id' => $this->appeal->instructor_id,
            'message' => 'An instructor appeal is awaiting SISA review.',
        ];
    }
}
