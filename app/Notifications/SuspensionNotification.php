<?php

namespace App\Notifications;

use App\Models\SafetyIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SuspensionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $incident;

    /**
     * Create a new notification instance.
     */
    public function __construct(SafetyIncident $incident)
    {
        $this->incident = $incident;
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
            ->subject('URGENT: Your SiaSurf Instructor Account Has Been Suspended')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('We regret to inform you that your instructor account has been suspended due to accumulated safety violations.')
            ->line('Incident Type: ' . $this->incident->type->label())
            ->line('Severity: ' . $this->incident->severity->label())
            ->line('Your account will be suspended for 30 days.')
            ->line('Please contact SISA admin for more information and to discuss reinstatement.')
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
            'type' => 'account_suspended',
            'incident_id' => $this->incident->id,
            'severity' => $this->incident->severity->value,
            'message' => 'Your instructor account has been suspended due to safety violations.',
        ];
    }
}