<?php

namespace App\Notifications;

use App\Models\SafetyIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CriticalIncidentNoticeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public SafetyIncident $incident;

    public function __construct(SafetyIncident $incident)
    {
        $this->incident = $incident->loadMissing(['booking']);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $summary = $this->instructorSummary();

        $mail = (new MailMessage)
            ->subject('Important SISA Critical Safety Notice')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('SISA recorded a critical safety incident involving your account for external handling with SISA officers (0 in-system strikes applied).')
            ->line('Summary: '.$summary);

        if ($this->incident->booking_id) {
            $mail->line('Related Booking: #SURF-'.str_pad((string) $this->incident->booking_id, 5, '0', STR_PAD_LEFT));
        }

        return $mail
            ->action('View Dashboard', route('instructor.dashboard'))
            ->line('Please coordinate directly with SISA Administration regarding next steps.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $summary = $this->instructorSummary();

        return [
            'type' => 'critical_incident_notice',
            'incident_id' => $this->incident->id,
            'booking_id' => $this->incident->booking_id,
            'message' => 'SISA recorded a critical safety matter for external handling: '.$summary,
            'url' => route('instructor.dashboard'),
        ];
    }

    /**
     * Only the SISA-cleared instructor summary is ever shared with the instructor;
     * internal admin_note, reporter identity, and raw report descriptions stay hidden.
     */
    private function instructorSummary(): string
    {
        $summary = trim((string) ($this->incident->instructor_summary ?? ''));

        return $summary !== ''
            ? $summary
            : 'Please contact SISA Administration regarding an escalated safety review.';
    }
}
