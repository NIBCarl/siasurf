<?php

namespace App\Notifications;

use App\Models\SafetyIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StrikeRevokedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected SafetyIncident $incident,
        protected string $reason,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('SISA Safety Strike Revoked')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A confirmed safety strike on your instructor record was revoked by SISA administration.')
            ->line('Reason: '.$this->reason)
            ->action('View Standing', route('instructor.dashboard'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'strike_revoked',
            'incident_id' => $this->incident->id,
            'message' => "A confirmed safety strike on your record was revoked by SISA: {$this->reason}",
            'url' => route('instructor.dashboard'),
        ];
    }
}
