<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CashRemittanceOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public float $overdueAmount,
        public int $overdueDaysCount,
        public string $earliestDueDate,
        public ?int $instructorId = null,
        public ?string $instructorName = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->isAdmin()
            ? ['database']
            : ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = '₱' . number_format($this->overdueAmount, 2);

        return (new MailMessage)
            ->subject('Action Required: Cash Service Fee Remittance Overdue')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("You have {$amount} in overdue cash service fees across {$this->overdueDaysCount} daily ledger entry/entries (earliest due date: {$this->earliestDueDate}).")
            ->line('Please visit the SISA Administration office to remit your pending cash service fees or reconcile them against your GCash earnings.')
            ->action('View Earnings Ledger', route('instructor.earnings.index'))
            ->line('Thank you for keeping your SiaSurf account in good standing.');
    }

    public function toArray(object $notifiable): array
    {
        $amount = '₱' . number_format($this->overdueAmount, 2);
        $isAdmin = $notifiable instanceof User && $notifiable->isAdmin();

        if ($isAdmin) {
            $instructorLabel = $this->instructorName ?? 'An instructor';

            return [
                'instructor_id' => $this->instructorId,
                'overdue_amount' => round($this->overdueAmount, 2),
                'overdue_days_count' => $this->overdueDaysCount,
                'earliest_due_date' => $this->earliestDueDate,
                'message' => "{$instructorLabel} has {$amount} in overdue cash service fees (earliest due {$this->earliestDueDate}).",
                'type' => 'cash_remittance_overdue',
                'url' => $this->instructorId
                    ? route('admin.payouts.show', $this->instructorId)
                    : route('admin.payouts.index'),
            ];
        }

        return [
            'overdue_amount' => round($this->overdueAmount, 2),
            'overdue_days_count' => $this->overdueDaysCount,
            'earliest_due_date' => $this->earliestDueDate,
            'message' => "Cash service fee remittance of {$amount} is overdue (earliest due {$this->earliestDueDate}).",
            'type' => 'cash_remittance_overdue',
            'url' => route('instructor.earnings.index'),
        ];
    }
}
