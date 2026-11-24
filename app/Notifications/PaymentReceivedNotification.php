<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\ResolvesNotificationUrl;
use App\Services\ReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Payment confirmation sent to both student and instructor with the official
 * PDF receipt attached once a payment is completed.
 */
class PaymentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable, ResolvesNotificationUrl;

    public function __construct(
        protected Payment $payment,
        protected ?string $receiptPath = null
    ) {
        $this->payment = $payment->loadMissing(['booking.student', 'booking.instructor', 'booking.surfSpot']);
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
        $this->payment->loadMissing(['booking.student', 'booking.instructor', 'booking.surfSpot', 'booking.payment']);

        $booking = $this->payment->booking;
        $isStudent = $booking !== null && $notifiable->id === $booking->student_id;
        $reference = $booking !== null
            ? '#SURF-'.str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT)
            : '#'.$this->payment->id;
        $receiptNumber = $booking !== null
            ? 'RCP-'.str_pad((string) $booking->id, 6, '0', STR_PAD_LEFT)
            : null;
        $amountFormatted = number_format((float) $this->payment->amount, 2);
        $methodLabel = $this->payment->payment_method?->label() ?? 'Payment';

        $subject = $isStudent
            ? "Official Receipt & Payment Confirmed ({$reference}) — SiaSurf"
            : "Lesson Payment Received & Receipt ({$reference}) — SiaSurf";

        $intro = match (true) {
            $booking === null => "We received a payment of PHP {$amountFormatted} ({$reference}).",
            $isStudent => "Thank you! Your payment of PHP {$amountFormatted} for booking {$reference} has been confirmed.",
            default => "Payment of PHP {$amountFormatted} has been received from ".($booking->student?->name ?? 'your student')." for booking {$reference}.",
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($intro);

        if ($receiptNumber !== null) {
            $mail->line("- Receipt No.: {$receiptNumber}");
        }

        $mail->line("- Payment Method: {$methodLabel}");

        if ($booking !== null) {
            $schedule = $booking->date?->format('M d, Y') ?? 'TBD';
            if ($booking->start_time && $booking->end_time) {
                $schedule .= ' • '.$booking->start_time->format('g:i A').' - '.$booking->end_time->format('g:i A');
            } elseif ($booking->time_period) {
                $schedule .= ' • '.$booking->time_period->label();
            }

            $mail->line("- Schedule: {$schedule}")
                ->line('- Surf Spot: '.($booking->surfSpot?->name ?? 'To be confirmed'))
                ->line($isStudent
                    ? '- Instructor: '.($booking->instructor?->name ?? 'Assigned Instructor')
                    : '- Student: '.($booking->student?->name ?? 'Student'));

            $receiptService = app(ReceiptService::class);
            $path = $this->receiptPath;

            if (empty($path) || ! Storage::disk('local')->exists($path)) {
                try {
                    $path = $receiptService->generateReceipt($booking);
                } catch (\Throwable $e) {
                    $path = null;
                    Log::warning('Failed to generate receipt PDF for payment email.', [
                        'booking_id' => $booking->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (! empty($path) && Storage::disk('local')->exists($path)) {
                $mail->line('A PDF copy of the official SiaSurf receipt is attached to this email for your records.');
                $mail->attachData(
                    Storage::disk('local')->get($path),
                    $receiptService->receiptFileName($booking),
                    ['mime' => 'application/pdf']
                );
            }
        }

        return $mail->action('View Booking', $this->bookingUrl($notifiable, $booking?->id) ?? $this->dashboardUrl($notifiable));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->payment->loadMissing(['booking.student']);

        $booking = $this->payment->booking;
        $isStudent = $booking !== null && $notifiable->id === $booking->student_id;
        $amountFormatted = number_format((float) $this->payment->amount, 2);

        $message = ($booking !== null && ! $isStudent)
            ? "Payment of PHP {$amountFormatted} received from ".($booking->student?->name ?? 'student').'.'
            : "Payment of PHP {$amountFormatted} received.";

        return [
            'type' => 'payment_received',
            'payment_id' => $this->payment->id,
            'booking_id' => $this->payment->booking_id,
            'amount' => (float) $this->payment->amount,
            'message' => $message,
            'url' => $this->bookingUrl($notifiable, $this->payment->booking_id)
                ?? $this->dashboardUrl($notifiable),
        ];
    }
}
