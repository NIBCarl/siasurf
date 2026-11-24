<?php

namespace App\Notifications;

use App\Enums\PayoutType;
use App\Models\InstructorPayout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayoutRecordedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public InstructorPayout $payout)
    {
        $this->payout = $payout->loadMissing(['instructor', 'recordedBy']);
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $gcash = '₱' . number_format((float) $this->payout->gcash_earnings_settled, 2);
        $cashFees = '₱' . number_format((float) $this->payout->cash_fees_settled, 2);
        $net = (float) $this->payout->net_payout_amount;
        $absNet = '₱' . number_format(abs($net), 2);
        $period = $this->payout->period_start->format('M d, Y') . ' – ' . $this->payout->period_end->format('M d, Y');

        $summaryLine = match ($this->payout->settlement_type) {
            PayoutType::Payout => "SISA recorded an in-person payout of {$absNet} to you ({$this->payout->payment_channel_label}).",
            PayoutType::Remittance => "SISA recorded your in-person cash fee remittance of {$absNet} ({$this->payout->payment_channel_label}).",
            PayoutType::Offset => 'SISA recorded a zero-cash offset settlement clearing your GCash earnings against cash service fees owed.',
        };

        $mail = (new MailMessage)
            ->subject("SiaSurf Settlement Recorded ({$this->payout->reference_code})")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($summaryLine)
            ->line("Reference: {$this->payout->reference_code}")
            ->line("Period Covered: {$period} ({$this->payout->ledger_days_count} day(s))")
            ->line("GCash Earnings Settled: {$gcash}")
            ->line("Cash Service Fees Deducted/Settled: {$cashFees}")
            ->line('Net Payable Settled: ' . ($net < 0 ? "-{$absNet}" : $absNet));

        if ($this->payout->receipt_reference) {
            $mail->line("Voucher / Receipt Ref: {$this->payout->receipt_reference}");
        }

        return $mail
            ->action('View Earnings Ledger', route('instructor.earnings.index'))
            ->line('Thank you for teaching safely with SiaSurf.');
    }

    public function toArray(object $notifiable): array
    {
        $net = (float) $this->payout->net_payout_amount;
        $absNet = '₱' . number_format(abs($net), 2);

        $message = match ($this->payout->settlement_type) {
            PayoutType::Payout => "In-person payout of {$absNet} recorded ({$this->payout->reference_code}).",
            PayoutType::Remittance => "Cash fee remittance of {$absNet} recorded ({$this->payout->reference_code}).",
            PayoutType::Offset => "Zero-cash offset settlement recorded ({$this->payout->reference_code}).",
        };

        return [
            'payout_id' => $this->payout->id,
            'reference_code' => $this->payout->reference_code,
            'settlement_type' => $this->payout->settlement_type?->value,
            'net_payout_amount' => $net,
            'gcash_earnings_settled' => (float) $this->payout->gcash_earnings_settled,
            'cash_fees_settled' => (float) $this->payout->cash_fees_settled,
            'message' => $message,
            'type' => 'payout_recorded',
            'url' => route('instructor.earnings.index'),
        ];
    }
}
