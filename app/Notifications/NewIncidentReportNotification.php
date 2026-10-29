<?php

namespace App\Notifications;

use App\Models\SafetyIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewIncidentReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected SafetyIncident $incident)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $category = $this->incident->categoryEnum();

        return (new MailMessage)
            ->subject('New student concern awaiting SISA review')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('A student submitted a private session concern.')
            ->line('Instructor: ' . ($this->incident->instructor?->name ?? 'Unknown'))
            ->line('Category: ' . ($category?->label() ?? 'Unclassified concern'))
            ->line('Booking: #SURF-' . str_pad((string) $this->incident->booking_id, 5, '0', STR_PAD_LEFT))
            ->line('This report has not added a strike yet. Review it and confirm or reject it.')
            ->action('Review Report', route('admin.incidents.show', $this->incident))
            ->line('SiaSurf Safety Monitoring');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'incident_report',
            'incident_id' => $this->incident->id,
            'instructor_id' => $this->incident->instructor_id,
            'category' => $this->incident->category,
            'message' => 'A new student concern is awaiting SISA review.',
        ];
    }
}
