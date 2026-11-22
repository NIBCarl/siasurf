<?php

namespace App\Notifications;

use App\Enums\RefundStatus;
use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RefundStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Refund $refund)
    {
        $this->refund = $refund->loadMissing(['booking.surfSpot', 'booking.instructor', 'booking.student']);
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->refund->booking;
        $ref = '#SURF-' . str_pad((string) $this->refund->booking_id, 5, '0', STR_PAD_LEFT);
        $amount = '₱' . number_format((float) $this->refund->amount, 2);
        $fee = '₱' . number_format((float) $this->refund->service_fee_retained, 2);

        $statusLine = match ($this->refund->status) {
            RefundStatus::Requested => "Your refund of {$amount} has been requested and queued for PayMongo processing.",
            RefundStatus::Processing => "Your refund of {$amount} is currently being processed by PayMongo.",
            RefundStatus::Completed => "Your refund of {$amount} has been completed.",
            RefundStatus::ManualRequired => "Your refund of {$amount} is queued for manual processing by SISA Administration.",
            RefundStatus::Failed => "Automatic PayMongo refund for {$amount} encountered an error and has been flagged for SISA Admin review.",
        };

        $mail = (new MailMessage)
            ->subject("Refund Update ({$ref}): {$this->refund->status_label}")
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($statusLine)
            ->line("Booking Reference: {$ref}")
            ->line("Policy Applied: {$this->refund->basis_label} ({$this->refund->refund_percentage}% of lesson price)")
            ->line("Refund Amount: {$amount}");

        if ((float) $this->refund->service_fee_retained > 0) {
            $mail->line("Service Fee Retained (non-refundable): {$fee}");
        }

        if ($this->refund->paymongo_refund_id) {
            $mail->line("Gateway Refund Ref: {$this->refund->paymongo_refund_id}");
        }

        if ($booking) {
            $mail->action('View Booking', route('student.bookings.show', $booking->id));
        }

        return $mail->line('Thank you for surfing safely with SiaSurf.');
    }

    public function toArray(object $notifiable): array
    {
        $ref = '#SURF-' . str_pad((string) $this->refund->booking_id, 5, '0', STR_PAD_LEFT);
        $amount = '₱' . number_format((float) $this->refund->amount, 2);

        return [
            'booking_id' => $this->refund->booking_id,
            'refund_id' => $this->refund->id,
            'status' => $this->refund->status?->value,
            'amount' => (float) $this->refund->amount,
            'service_fee_retained' => (float) $this->refund->service_fee_retained,
            'message' => "Refund of {$amount} for {$ref}: {$this->refund->status_label}.",
            'type' => 'refund_status_updated',
        ];
    }
}
