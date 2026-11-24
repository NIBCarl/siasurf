<?php

namespace App\Services;

use App\Contracts\PaymentServiceInterface;
use App\Models\Booking;
use App\Models\Payment;
use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MockPaymentService implements PaymentServiceInterface
{
    /**
     * Simulated payment storage (in-memory for development)
     */
    protected static array $simulatedPayments = [];

    /**
     * Create a mock payment intent
     */
    public function createPaymentIntent(Booking $booking, array $options = []): array
    {
        $transactionId = 'mock_' . Str::random(16);
        $forceSuccess = $options['forceSuccess'] ?? true;

        // Store simulation data
        self::$simulatedPayments[$transactionId] = [
            'booking_id' => $booking->id,
            'amount' => $booking->total_amount,
            'status' => 'pending',
            'force_success' => $forceSuccess,
            'created_at' => now(),
        ];

        return [
            'id' => $transactionId,
            'type' => 'payment_intent',
            'attributes' => [
                'amount' => $booking->total_amount * 100, // In cents
                'currency' => 'PHP',
                'status' => 'awaiting_payment_method',
                'description' => "Surf lesson booking #{$booking->id}",
                'metadata' => [
                    'booking_id' => $booking->id,
                    'student_id' => $booking->student_id,
                    'instructor_id' => $booking->instructor_id,
                ],
            ],
            'checkout_url' => route('payments.mock-checkout', [
                'transactionId' => $transactionId,
                'force_success' => $forceSuccess,
            ]),
            'transaction_id' => $transactionId,
        ];
    }

    /**
     * Handle mock webhook
     */
    public function handleWebhook(Request $request): void
    {
        $event = $request->input('data.attributes');
        
        if ($event['type'] === 'payment.paid') {
            $bookingId = $event['data']['attributes']['metadata']['booking_id'];
            $transactionId = $event['data']['id'];
            
            $this->processSuccessfulPayment($bookingId, $transactionId);
        }
    }

    /**
     * Verify webhook signature (always returns true in mock)
     */
    public function verifySignature(string $signature, string $payload): bool
    {
        return true;
    }

    /**
     * Get mock payment status
     */
    public function getPaymentStatus(string $transactionId): string
    {
        if (!isset(self::$simulatedPayments[$transactionId])) {
            return 'unknown';
        }

        return self::$simulatedPayments[$transactionId]['status'];
    }

    /**
     * Process mock refund
     */
    public function processRefund(
        Booking $booking,
        ?float $amount = null,
        string $reason = 'requested_by_customer',
        array $metadata = []
    ): array {
        $refundId = 'mock_refund_' . Str::random(16);
        $refundAmount = $amount ?? $booking->effectiveLessonPrice();

        return [
            'id' => $refundId,
            'type' => 'refund',
            'attributes' => [
                'amount' => (int) round($refundAmount * 100),
                'currency' => 'PHP',
                'status' => 'succeeded',
                'reason' => $reason,
                'payment_id' => $booking->payment?->gateway_payment_id ?? $booking->payment?->transaction_id,
                'metadata' => $metadata,
            ],
        ];
    }

    /**
     * Simulate payment completion
     */
    public function simulatePayment(string $transactionId, bool $success): void
    {
        $dbPayment = Payment::where('transaction_id', $transactionId)->first();

        if (!$dbPayment) {
            throw new \InvalidArgumentException("Transaction {$transactionId} not found");
        }

        if ($success) {
            $this->processSuccessfulPayment($dbPayment->booking_id, $transactionId);
        } else {
            $this->processFailedPayment($dbPayment->booking_id, $transactionId);
        }
    }

    /**
     * Process successful payment
     */
    private function processSuccessfulPayment(int $bookingId, string $transactionId): void
    {
        $booking = \Illuminate\Support\Facades\DB::transaction(function () use ($bookingId, $transactionId) {
            $locked = Booking::with('payment')
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === \App\Enums\BookingStatus::Confirmed && $locked->payment_status === PaymentStatus::Completed) {
                return null;
            }

            if (! in_array($locked->status, [
                \App\Enums\BookingStatus::AwaitingPayment,
                \App\Enums\BookingStatus::Confirmed,
            ], true)) {
                return null;
            }

            $locked->update([
                'status' => 'confirmed',
                'payment_status' => PaymentStatus::Completed,
                'payment_deadline_at' => null,
            ]);

            if ($locked->payment) {
                $locked->payment->update([
                    'status' => PaymentStatus::Completed,
                    'transaction_id' => $transactionId,
                    'gateway_payment_id' => $locked->payment->gateway_payment_id ?: ('mock_pay_' . Str::random(12)),
                    'paid_at' => $locked->payment->paid_at ?? now(),
                ]);
            }

            return $locked->fresh(['student', 'instructor', 'surfSpot', 'payment']);
        });

        if (! $booking) {
            return;
        }

        // Update simulated storage
        self::$simulatedPayments[$transactionId]['status'] = 'paid';

        $eventService = app(BookingEventService::class);
        if ($booking->payment) {
            $eventService->recordPaymentCompleted($booking, $booking->payment->fresh(), $booking->student_id);
        }
        $eventService->recordConfirmed($booking, $booking->student_id, 'GCash');

        // Phase 11 - tell the student and the admin dashboard in real time.
        // This was previously a TODO, so the `.payment.received` listeners in
        // useNotifications.ts never fired.
        $this->broadcastPaymentReceived($booking);
    }

    /**
     * Broadcast payment success without letting a dead socket fail the payment.
     */
    private function broadcastPaymentReceived(Booking $booking): void
    {
        $booking->loadMissing(['student', 'instructor', 'surfSpot', 'payment']);

        try {
            if ($booking->payment) {
                broadcast(new \App\Events\PaymentReceived($booking->payment->fresh()))->toOthers();
            }

            broadcast(new \App\Events\BookingConfirmed($booking))->toOthers();
        } catch (\Throwable $exception) {
            \Log::warning('Failed to broadcast payment received.', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }

        app(ReceiptService::class)->sendPaymentReceipts($booking);
    }

    /**
     * Process failed payment
     */
    private function processFailedPayment(int $bookingId, string $transactionId): void
    {
        $booking = Booking::findOrFail($bookingId);
        
        // Update payment record
        if ($booking->payment) {
            $booking->payment->update([
                'status' => PaymentStatus::Failed,
                'transaction_id' => $transactionId,
            ]);
        }

        // Update simulated storage
        self::$simulatedPayments[$transactionId]['status'] = 'failed';
    }

    /**
     * Get all simulated payments (for debugging)
     */
    public static function getSimulatedPayments(): array
    {
        return self::$simulatedPayments;
    }

    /**
     * Clear simulated payments (for testing)
     */
    public static function clearSimulatedPayments(): void
    {
        self::$simulatedPayments = [];
    }
}
