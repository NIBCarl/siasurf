<?php

namespace App\Notifications;

use App\Enums\IncidentOutcome;
use App\Enums\IncidentReportTopic;
use App\Enums\IncidentStatus;
use App\Models\SafetyIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IncidentOutcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected SafetyIncident $incident) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $confirmed = $this->incident->status === IncidentStatus::Confirmed;
        $isCritical = $this->incident->isExternallyHandled();
        $topic = $this->incident->report_type
            ? IncidentReportTopic::tryFrom((string) $this->incident->report_type)?->label()
            : null;
        $reason = $this->incident->decision_reason ?? $this->incident->rejection_reason;

        $subject = match (true) {
            $isCritical => 'Your safety report was escalated by SISA',
            $confirmed => 'Your session report was reviewed and confirmed',
            default => 'Your session report was reviewed by SISA',
        };

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('Hello '.$notifiable->name.',');

        if ($this->incident->booking_id) {
            $message->line('SISA reviewed the report you submitted for booking #SURF-'
                .str_pad((string) $this->incident->booking_id, 5, '0', STR_PAD_LEFT).'.');
        } else {
            $message->line('SISA reviewed the safety report you submitted.');
        }

        if ($topic) {
            $message->line('Report type: '.$topic);
        }

        if ($isCritical) {
            $message->line('Outcome: Escalated as a critical report — SISA is handling this case outside the platform with the proper authorities.');
        } elseif ($confirmed) {
            $message->line('Outcome: Confirmed by SISA ('.($this->incident->final_outcome?->label() ?? 'Action recorded').').');
        } else {
            $message->line('Outcome: '.IncidentOutcome::NoStrike->label().'.');
        }

        if ($reason) {
            $message->line('SISA decision reason: '.$reason);
        }

        if ($this->incident->booking_id) {
            $message->action('View Booking', route('student.bookings.show', $this->incident->booking_id));
        }

        return $message->line('SiaSurf Safety Monitoring');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'incident_outcome',
            'incident_id' => $this->incident->id,
            'status' => $this->incident->status?->value,
            'final_outcome' => $this->incident->final_outcome?->value,
            'handled_externally' => $this->incident->isExternallyHandled(),
            'decision_reason' => $this->incident->decision_reason ?? $this->incident->rejection_reason,
            'message' => 'Your session report has been reviewed by SISA.',
            'url' => $this->incident->booking_id
                ? route('student.bookings.show', $this->incident->booking_id)
                : route('student.dashboard'),
        ];
    }
}
