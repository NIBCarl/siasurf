<?php

namespace App\Notifications;

use App\Models\InstructorFine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FineSettledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected InstructorFine $fine,
        protected bool $waived,
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
        $amount = number_format((float) $this->fine->amount, 2);

        $subject = $this->waived ? 'SISA Fine Waived' : 'SISA Fine Marked as Paid';
        $line = $this->waived
            ? "Your ₱{$amount} SISA fine has been waived."
            : "Your ₱{$amount} SISA fine has been recorded as paid.";

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($line);

        if ($this->fine->settlement_note) {
            $mail->line('Note: '.$this->fine->settlement_note);
        }

        return $mail->action('View Dashboard', route('instructor.dashboard'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $amount = number_format((float) $this->fine->amount, 2);

        return [
            'type' => 'fine_settled',
            'fine_id' => $this->fine->id,
            'status' => $this->waived ? 'waived' : 'paid',
            'message' => $this->waived
                ? "Your ₱{$amount} SISA fine was waived."
                : "Your ₱{$amount} SISA fine has been marked as paid.",
            'url' => route('instructor.dashboard'),
        ];
    }
}
