<?php

namespace App\Notifications;

use App\Models\InstructorStrike;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InstructorStrikeRecordedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected InstructorStrike $strike)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A confirmed concern added a strike to your record')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('SISA confirmed a session concern and recorded a strike on your instructor record.')
            ->line('Reason: ' . ($this->strike->reason_text ?? $this->strike->reason_code ?? 'Confirmed concern'))
            ->line('Strike weight: ' . $this->strike->weight)
            ->line('You can view your standing on your dashboard. If this is incorrect, contact SISA.')
            ->action('View Dashboard', route('instructor.dashboard'))
            ->line('SiaSurf Safety Monitoring');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'strike_recorded',
            'strike_id' => $this->strike->id,
            'weight' => $this->strike->weight,
            'message' => 'A confirmed concern added a strike to your record.',
        ];
    }
}
