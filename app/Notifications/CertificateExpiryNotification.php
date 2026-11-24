<?php

namespace App\Notifications;

use App\Models\Certificate;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CertificateExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Certificate $certificate,
        protected bool $expired,
        protected ?int $daysLeft = null,
    ) {
        $this->certificate = $certificate->loadMissing('user');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->isAdmin()
            ? ['database']
            : ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $certLabel = $this->certificate->type?->label() ?? 'SISA';
        $expiryDate = $this->certificate->expires_at?->format('M d, Y') ?? 'soon';

        if ($this->expired) {
            return (new MailMessage)
                ->subject('Certificate Expired — Profile Hidden')
                ->greeting('Hello '.$notifiable->name.',')
                ->line("Your {$certLabel} certificate expired on {$expiryDate}.")
                ->line('Your instructor profile is hidden from student bookings until a renewed certificate is verified by SISA.')
                ->action('Upload Renewed Certificate', route('instructor.profile.edit'));
        }

        $window = $this->daysLeft !== null
            ? "in {$this->daysLeft} day(s) ({$expiryDate})"
            : "soon ({$expiryDate})";

        return (new MailMessage)
            ->subject('Certificate Expiring Soon')
            ->greeting('Hello '.$notifiable->name.',')
            ->line("Your {$certLabel} certificate will expire {$window}.")
            ->line('Please renew and upload an updated document before it lapses to stay visible in student search.')
            ->action('Manage Certificates', route('instructor.profile.edit'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $certLabel = $this->certificate->type?->label() ?? 'Certificate';
        $instructorName = $this->certificate->user?->name ?? 'An instructor';
        $expiryDate = $this->certificate->expires_at?->format('M j, Y');
        $isAdmin = $notifiable instanceof User && $notifiable->isAdmin();

        if ($this->expired) {
            $message = $isAdmin
                ? "{$instructorName}'s {$certLabel} certificate has expired."
                : "Your {$certLabel} certificate has expired. Upload a renewal to stay visible for bookings.";
        } else {
            $daysLabel = $this->daysLeft !== null ? "in {$this->daysLeft} day(s)" : 'soon';
            $dateSuffix = $expiryDate ? " ({$expiryDate})" : '';
            $message = $isAdmin
                ? "{$instructorName}'s {$certLabel} certificate expires {$daysLabel}{$dateSuffix}."
                : "Your {$certLabel} certificate expires {$daysLabel}{$dateSuffix}.";
        }

        return [
            'type' => $this->expired ? 'certificate_expired' : 'certificate_expiring_soon',
            'certificate_id' => $this->certificate->id,
            'expires_at' => $this->certificate->expires_at?->toDateString(),
            'instructor_id' => $this->certificate->user_id,
            'message' => $message,
            'url' => $isAdmin
                ? route('admin.instructors.show', $this->certificate->user_id)
                : route('instructor.profile.edit'),
        ];
    }
}
