<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InstructorSuspended extends Notification
{
    use Queueable;

    protected string $reason;

    protected int $duration;

    protected ?int $strikeTier;

    protected bool $requiresSisaIdConfiscation;

    public function __construct(
        string $reason,
        int $duration,
        ?int $strikeTier = null,
        bool $requiresSisaIdConfiscation = false
    ) {
        $this->reason = $reason;
        $this->duration = $duration;
        $this->strikeTier = $strikeTier;
        $this->requiresSisaIdConfiscation = $requiresSisaIdConfiscation;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tierLine = match ($this->strikeTier) {
            2 => 'Tier applied: 1-week suspension (2 active strikes).',
            3 => 'Tier applied: 1-month suspension (3 active strikes).',
            default => 'Suspension duration: '.$this->duration.' day(s).',
        };

        $mail = (new MailMessage)
            ->subject('Your Instructor Account Has Been Suspended')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your instructor account has been suspended for '.$this->duration.' day(s).')
            ->line($tierLine)
            ->line('Reason for suspension: '.$this->reason)
            ->line('Your profile is hidden from booking until the suspension period ends and a SISA administrator approves reinstatement.');

        if ($this->requiresSisaIdConfiscation) {
            $mail->line('Important: A 3-strike (1-month) suspension requires surrendering/confiscating your physical SISA ID outside the system with SISA officers.');
        }

        return $mail
            ->action('View Suspension & Appeal', route('instructor.suspended'))
            ->line('If you believe this is an error, you may submit one appeal for this suspension.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'instructor_suspended',
            'reason' => $this->reason,
            'duration' => $this->duration,
            'strike_tier' => $this->strikeTier,
            'requires_sisa_id_confiscation' => $this->requiresSisaIdConfiscation,
            'message' => 'Your instructor account has been suspended for '.$this->duration.' day(s). Reinstatement requires SISA approval.',
            'url' => route('instructor.suspended'),
        ];
    }
}
