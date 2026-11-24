<?php

namespace App\Notifications;

use App\Enums\IncidentOutcome;
use App\Enums\IncidentReportTopic;
use App\Models\SafetyIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewIncidentReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected SafetyIncident $incident) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $category = $this->incident->categoryEnum();
        $suggestedOutcome = $this->incident->suggested_outcome;
        $isCritical = $suggestedOutcome === IncidentOutcome::Critical;
        $topic = $this->incident->report_type
            ? IncidentReportTopic::tryFrom((string) $this->incident->report_type)?->label()
            : null;

        $subject = $isCritical
            ? '[CRITICAL - External Handling] New student report awaiting SISA review'
            : 'New student concern awaiting SISA review';

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A student submitted a private safety report.')
            ->line('Instructor: '.($this->incident->instructor?->name ?? 'Unknown'))
            ->line('Report type: '.($topic ?? 'General concern'))
            ->line('Primary item: '.($category?->label() ?? 'Unclassified concern'))
            ->line('Suggested outcome (SISA-only): '.($suggestedOutcome?->label() ?? 'Unclassified'));

        if ($this->incident->booking_id) {
            $mail->line('Booking: #SURF-'.str_pad((string) $this->incident->booking_id, 5, '0', STR_PAD_LEFT));
        }

        if ($isCritical) {
            $mail->line('Notice: Suggested critical reports are handled by SISA outside the system and require prompt review.');
        }

        return $mail
            ->line('This report has not added a strike yet. Review it and record SISA\'s final decision.')
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
            'suggested_outcome' => $this->incident->suggested_outcome?->value,
            'message' => 'A new student safety report is awaiting SISA review.',
            'url' => route('admin.incidents.show', $this->incident),
        ];
    }
}
