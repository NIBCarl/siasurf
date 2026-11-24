<?php

namespace App\Notifications;

use App\Models\Certificate;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CertificateStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Certificate $certificate,
        protected bool $verified,
        protected ?string $notes = null,
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
        $certLabel = $this->certificate->type?->label() ?? 'Certificate';
        $url = $this->resolveUrl($notifiable);

        if ($this->verified) {
            $mail = (new MailMessage)
                ->subject('Certificate Verified')
                ->greeting('Hello '.$notifiable->name.',')
                ->line("Your {$certLabel} certificate has been verified by SISA.");

            if ($this->certificate->expires_at) {
                $mail->line('- Expires: '.$this->certificate->expires_at->format('M d, Y'));
            }

            return $mail
                ->action('View Profile', $url)
                ->line('Thank you for keeping your credentials up to date.');
        }

        $mail = (new MailMessage)
            ->subject('Certificate Rejected — Action Needed')
            ->greeting('Hello '.$notifiable->name.',')
            ->line("Your {$certLabel} certificate was not approved by SISA.");

        if ($this->notes) {
            $mail->line('Reason: '.$this->notes);
        }

        return $mail
            ->action('Update Certificates', $url)
            ->line('Please upload a valid replacement document.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $certLabel = $this->certificate->type?->label() ?? 'Certificate';

        $message = $this->verified
            ? "Your {$certLabel} certificate was verified by SISA."
            : "Your {$certLabel} certificate was rejected"
                .($this->notes ? ": {$this->notes}" : '.');

        return [
            'type' => $this->verified ? 'certificate_verified' : 'certificate_rejected',
            'certificate_id' => $this->certificate->id,
            'message' => $message,
            'url' => $this->resolveUrl($notifiable),
        ];
    }

    private function resolveUrl(object $notifiable): string
    {
        if (! $notifiable instanceof User) {
            return route('instructor.pending');
        }

        if ($notifiable->isVerifiedInstructor()) {
            return route('instructor.profile.edit');
        }

        return $notifiable->needsInstructorOnboarding()
            ? route('instructor.onboarding')
            : route('instructor.pending');
    }
}
