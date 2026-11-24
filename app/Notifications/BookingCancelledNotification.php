<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Concerns\ResolvesNotificationUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable, ResolvesNotificationUrl;

    protected Booking $booking;
    protected ?string $reason;
    protected ?array $refundSummary;

    /**
     * Create a new notification instance.
     */
    public function __construct(Booking $booking, ?string $reason = null, ?array $refundSummary = null)
    {
        $this->booking = $booking;
        $this->reason = $reason;
        $this->refundSummary = $refundSummary;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $roleMessage = $notifiable->id === $this->booking->student_id
            ? "Your booking with instructor {$this->booking->instructor->name} has been cancelled."
            : "The booking by student {$this->booking->student->name} has been cancelled.";

        $mail = (new MailMessage)
            ->subject('Surf Lesson Booking Cancelled')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($roleMessage)
            ->line('Booking Details:')
            ->line('- Date: ' . ($this->booking->date instanceof \DateTimeInterface ? $this->booking->date->format('Y-m-d') : $this->booking->date))
            ->line('- Time Period: ' . ($this->booking->time_period instanceof \BackedEnum ? $this->booking->time_period->label() : $this->booking->time_period))
            ->line('- Spot: ' . ($this->booking->surfSpot?->name ?? 'Assigned surf spot'))
            ->line('- Reason: ' . ($this->reason ?? 'Payment window expired.'));

        if ($this->refundSummary && $notifiable->id === $this->booking->student_id) {
            $refundAmount = (float) ($this->refundSummary['refund_amount'] ?? 0);
            $feeRetained = (float) ($this->refundSummary['service_fee_retained'] ?? 0);

            if ($refundAmount > 0) {
                $mail->line('- Refund: ₱' . number_format($refundAmount, 2) . ' (' . ($this->refundSummary['status_label'] ?? 'Requested') . ')');
                if ($feeRetained > 0) {
                    $mail->line('- Service Fee Retained (non-refundable): ₱' . number_format($feeRetained, 2));
                }
            } elseif (! empty($this->refundSummary['summary'])) {
                $mail->line('- Refund: ' . $this->refundSummary['summary']);
            }
        }

        return $mail
            ->action(
                'View Booking',
                $this->bookingUrl($notifiable, $this->booking->id) ?? $this->dashboardUrl($notifiable)
            )
            ->line('If you have any questions, please contact support.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'message' => 'Booking was cancelled: ' . ($this->reason ?? 'Payment window expired.'),
            'reason' => $this->reason,
            'refund_summary' => $this->refundSummary,
            'type' => 'booking_cancelled',
            'url' => $this->bookingUrl($notifiable, $this->booking->id)
                ?? $this->dashboardUrl($notifiable),
        ];
    }
}
