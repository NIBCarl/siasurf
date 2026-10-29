<?php

namespace App\Notifications;

use App\Models\SafetyIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IncidentOutcomeNotification extends Notification implements ShouldQueue
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
        $confirmed = $this->incident->status === \App\Enums\IncidentStatus::Confirmed;
        $category = $this->incident->categoryEnum();

        $message = (new MailMessage)
            ->subject($confirmed ? 'Your session concern was confirmed' : 'Your session concern was reviewed')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('SISA reviewed the concern you submitted for booking #SURF-'
                . str_pad((string) $this->incident->booking_id, 5, '0', STR_PAD_LEFT) . '.')
            ->line('Category: ' . ($category?->label() ?? 'Unclassified concern'));

        if ($confirmed) {
            $message->line('The report was confirmed and the instructor record was updated.')
                ->line('Thank you for helping keep Siargao surf lessons safe.');
        } else {
            $message->line('The report was reviewed and no violation was confirmed.')
                ->line('You can continue to leave honest public reviews for future sessions.');
        }

        return $message->action('View Booking', route('student.bookings.show', $this->incident->booking_id))
            ->line('SiaSurf Safety Monitoring');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'incident_outcome',
            'incident_id' => $this->incident->id,
            'status' => $this->incident->status?->value,
            'message' => 'Your session concern has been reviewed by SISA.',
        ];
    }
}
