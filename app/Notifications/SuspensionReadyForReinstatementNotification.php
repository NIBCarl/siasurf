<?php

namespace App\Notifications;

use App\Models\InstructorSuspension;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SuspensionReadyForReinstatementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected InstructorSuspension $suspension) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $instructorName = $this->suspension->instructor?->name ?? 'Instructor';

        return (new MailMessage)
            ->subject('Instructor Suspension Ready for Reinstatement Review')
            ->greeting('Hello '.$notifiable->name.',')
            ->line($instructorName.' has completed their '.$this->suspension->tierLabel().' period.')
            ->line('Their profile remains hidden from booking until a SISA administrator approves reinstatement.')
            ->action('Review Instructor Dossier', route('admin.instructors.show', $this->suspension->instructor_id))
            ->line('SiaSurf Safety Monitoring');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'suspension_awaiting_reinstatement',
            'suspension_id' => $this->suspension->id,
            'instructor_id' => $this->suspension->instructor_id,
            'message' => ($this->suspension->instructor?->name ?? 'An instructor').' has completed their suspension period and awaits SISA reinstatement.',
            'url' => route('admin.instructors.show', $this->suspension->instructor_id),
        ];
    }
}
