<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SisaIdExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected User $instructor,
        protected string $sisaIdCode,
        protected CarbonInterface $expiresAt,
        protected bool $expired,
        protected ?int $daysLeft = null,
    ) {}

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
        $expiryDate = $this->expiresAt->format('M d, Y');

        if ($this->expired) {
            return (new MailMessage)
                ->subject('SISA ID Expired — Renewal Required')
                ->greeting('Hello '.$notifiable->name.',')
                ->line("Your SISA Instructor ID ({$this->sisaIdCode}) expired on {$expiryDate}.")
                ->line('Please coordinate with SISA and upload renewed credentials to maintain active verification status.')
                ->action('View SISA ID Pass', route('instructor.qr.show'));
        }

        $window = $this->daysLeft !== null
            ? "in {$this->daysLeft} day(s) ({$expiryDate})"
            : "soon ({$expiryDate})";

        return (new MailMessage)
            ->subject('SISA ID Expiring Soon')
            ->greeting('Hello '.$notifiable->name.',')
            ->line("Your SISA Instructor ID ({$this->sisaIdCode}) will expire {$window}.")
            ->line('Please renew your SISA ID / underlying certification before it lapses to avoid service disruption.')
            ->action('View SISA ID Pass', route('instructor.qr.show'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $expiryDate = $this->expiresAt->format('M j, Y');
        $isAdmin = $notifiable instanceof User && $notifiable->isAdmin();

        if ($this->expired) {
            $message = $isAdmin
                ? "{$this->instructor->name}'s SISA ID ({$this->sisaIdCode}) expired on {$expiryDate}."
                : "Your SISA ID ({$this->sisaIdCode}) expired on {$expiryDate}. Please renew with SISA.";
        } else {
            $daysLabel = $this->daysLeft !== null ? "in {$this->daysLeft} day(s)" : 'soon';
            $message = $isAdmin
                ? "{$this->instructor->name}'s SISA ID ({$this->sisaIdCode}) expires {$daysLabel} ({$expiryDate})."
                : "Your SISA ID ({$this->sisaIdCode}) expires {$daysLabel} ({$expiryDate}).";
        }

        return [
            'type' => $this->expired ? 'sisa_id_expired' : 'sisa_id_expiring_soon',
            'sisa_id_code' => $this->sisaIdCode,
            'expires_at' => $this->expiresAt->toDateString(),
            'instructor_id' => $this->instructor->id,
            'message' => $message,
            'url' => $isAdmin
                ? route('admin.instructors.show', $this->instructor->id)
                : route('instructor.qr.show'),
        ];
    }
}
