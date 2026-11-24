<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Concerns\ResolvesNotificationUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CashUnderpaymentFlaggedNotification extends Notification implements ShouldQueue
{
    use Queueable, ResolvesNotificationUrl;

    public function __construct(
        protected Booking $booking,
        protected float $amountReceived,
        protected float $totalDue,
        protected float $shortfall,
        protected string $notes,
    ) {
        $this->booking = $booking->loadMissing(['student', 'instructor']);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ref = '#SURF-'.str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT);
        $received = '₱'.number_format($this->amountReceived, 2);
        $due = '₱'.number_format($this->totalDue, 2);
        $shortfall = '₱'.number_format($this->shortfall, 2);
        $isStudent = $notifiable->id === $this->booking->student_id;

        if ($isStudent) {
            return (new MailMessage)
                ->subject("Cash Underpayment Recorded ({$ref})")
                ->greeting('Hello '.$notifiable->name.',')
                ->line("Your cash payment for booking {$ref} was recorded as underpaid: {$received} received of {$due} (unpaid balance: {$shortfall}).")
                ->line('Note: '.$this->notes)
                ->line('SISA Administration has been notified to review this balance.')
                ->action('View Booking', $this->bookingUrl($notifiable, $this->booking->id) ?? $this->dashboardUrl($notifiable));
        }

        $studentName = $this->booking->student?->name ?? 'The student';

        return (new MailMessage)
            ->subject("Cash Underpayment Flagged for Review ({$ref})")
            ->greeting('Hello '.$notifiable->name.',')
            ->line("{$studentName} underpaid cash for booking {$ref}: {$received} collected of {$due} (shortfall: {$shortfall}).")
            ->line('Explanation: '.$this->notes)
            ->action('Review Booking', $this->bookingUrl($notifiable, $this->booking->id) ?? $this->dashboardUrl($notifiable));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $ref = '#SURF-'.str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT);
        $received = '₱'.number_format($this->amountReceived, 2);
        $due = '₱'.number_format($this->totalDue, 2);
        $shortfall = '₱'.number_format($this->shortfall, 2);
        $isStudent = $notifiable->id === $this->booking->student_id;
        $studentName = $this->booking->student?->name ?? 'Student';

        $message = $isStudent
            ? "Cash payment for {$ref} was recorded as underpaid ({$received} of {$due}; {$shortfall} remaining)."
            : "{$studentName} underpaid cash on {$ref} ({$received} of {$due}; shortfall {$shortfall}).";

        return [
            'type' => 'cash_underpayment_flagged',
            'booking_id' => $this->booking->id,
            'shortfall' => round($this->shortfall, 2),
            'message' => $message,
            'url' => $this->bookingUrl($notifiable, $this->booking->id),
        ];
    }
}
