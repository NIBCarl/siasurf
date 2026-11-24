<?php

namespace App\Notifications;

use App\Models\SkillUpgradeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SkillUpgradeResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected SkillUpgradeRequest $upgradeRequest,
        protected bool $approved,
    ) {
        $this->upgradeRequest = $upgradeRequest->loadMissing(['student', 'instructor', 'booking']);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->id === $this->upgradeRequest->student_id
            ? ['database', 'mail']
            : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $to = ucfirst((string) $this->upgradeRequest->requested_level);

        if ($this->approved) {
            return (new MailMessage)
                ->subject('Surf Skill Level Upgraded')
                ->greeting('Congratulations, '.$notifiable->name.'!')
                ->line("SISA approved your instructor's skill upgrade recommendation. Your surf skill level is now {$to}.")
                ->action('View Dashboard', route('student.dashboard'));
        }

        return (new MailMessage)
            ->subject('Skill Upgrade Request Update')
            ->greeting('Hello '.$notifiable->name.',')
            ->line("Your skill upgrade request to {$to} was not approved at this time.")
            ->action('View Dashboard', route('student.dashboard'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $isStudent = $notifiable->id === $this->upgradeRequest->student_id;
        $studentName = $this->upgradeRequest->student?->name ?? 'The student';
        $to = ucfirst((string) $this->upgradeRequest->requested_level);

        if ($isStudent) {
            $message = $this->approved
                ? "Your surf skill level has been upgraded to {$to}!"
                : "Your skill upgrade request to {$to} was not approved.";
            $url = route('student.dashboard');
        } else {
            $message = $this->approved
                ? "SISA approved your skill upgrade recommendation for {$studentName} ({$to})."
                : "SISA declined your skill upgrade recommendation for {$studentName} ({$to}).";
            $url = $this->upgradeRequest->booking_id
                ? route('instructor.bookings.show', $this->upgradeRequest->booking_id)
                : route('instructor.dashboard');
        }

        return [
            'type' => $this->approved ? 'skill_upgrade_approved' : 'skill_upgrade_rejected',
            'skill_upgrade_request_id' => $this->upgradeRequest->id,
            'message' => $message,
            'url' => $url,
        ];
    }
}
