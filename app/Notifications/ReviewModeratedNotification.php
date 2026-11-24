<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReviewModeratedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $action,
        public ?int $bookingId,
        public string $instructorName,
        public ?string $reason = null,
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
        [$subject, $intro] = match ($this->action) {
            'hide' => [
                'Your Surf Lesson Review Was Hidden',
                "SISA moderation hid your review for {$this->instructorName}.",
            ],
            'unhide' => [
                'Your Surf Lesson Review Was Restored',
                "SISA moderation restored your review for {$this->instructorName}. It is visible again.",
            ],
            default => [
                'Your Surf Lesson Review Was Removed',
                "SISA moderation removed your review for {$this->instructorName}.",
            ],
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($intro);

        if (filled($this->reason)) {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail
            ->action('View Booking', $this->destinationUrl())
            ->line('Thank you for helping keep SiaSurf reviews constructive and respectful.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $type = match ($this->action) {
            'hide' => 'review_hidden',
            'unhide' => 'review_restored',
            default => 'review_deleted',
        };

        $message = match ($this->action) {
            'hide' => "Your review for {$this->instructorName} was hidden by SISA moderation.",
            'unhide' => "Your review for {$this->instructorName} was restored and is visible again.",
            default => "Your review for {$this->instructorName} was removed by SISA moderation.",
        };

        if (filled($this->reason)) {
            $message .= ' Reason: '.$this->reason;
        }

        return [
            'type' => $type,
            'action' => $this->action,
            'booking_id' => $this->bookingId,
            'reason' => $this->reason,
            'message' => $message,
            'url' => $this->destinationUrl(),
        ];
    }

    private function destinationUrl(): string
    {
        return $this->bookingId
            ? route('student.bookings.show', $this->bookingId)
            : route('student.dashboard');
    }
}
