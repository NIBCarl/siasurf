<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts SISA administrators when an instructor submits or resubmits their
 * profile and certificates for verification.
 */
class InstructorPendingVerificationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected User $instructor,
        protected int $level,
        protected int $certificateCount,
        protected bool $isResubmission = false,
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
        $subject = $this->isResubmission
            ? 'Instructor Re-submitted Application for Verification'
            : 'Instructor Application Pending Verification';

        $intro = $this->isResubmission
            ? "{$this->instructor->name} resubmitted their instructor application for SISA verification."
            : "{$this->instructor->name} submitted a new instructor application awaiting SISA verification.";

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($intro)
            ->line('- Instructor: '.$this->instructor->name.' ('.$this->instructor->email.')')
            ->line('- Applied Level: Level '.$this->level)
            ->line('- Certificates Uploaded: '.$this->certificateCount)
            ->action('Review Instructor', route('admin.instructors.show', $this->instructor->id))
            ->line('SiaSurf Verification Queue');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $message = $this->isResubmission
            ? "{$this->instructor->name} resubmitted their Level {$this->level} instructor application for SISA review."
            : "{$this->instructor->name} submitted a Level {$this->level} instructor application ({$this->certificateCount} cert(s)) awaiting SISA verification.";

        return [
            'type' => 'instructor_pending_verification',
            'instructor_id' => $this->instructor->id,
            'is_resubmission' => $this->isResubmission,
            'message' => $message,
            'url' => route('admin.instructors.show', $this->instructor->id),
        ];
    }
}
