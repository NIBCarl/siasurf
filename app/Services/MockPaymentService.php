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
                'transaction_id' => $transactionId,
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
    public function processRefund(Booking $booking, ?float $amount = null): array
    {
        $refundId = 'mock_refund_' . Str::random(16);
        $refundAmount = $amount ?? $booking->total_amount;

        // Update payment status
        if ($booking->payment) {
            $booking->payment->update([
                'status' => PaymentStatus::Refunded,
                'refunded_at' => now(),
            ]);
        }

        return [
            'id' => $refundId,
            'type' => 'refund',
            'attributes' => [
                'amount' => $refundAmount * 100,
                'currency' => 'PHP',
                'status' => 'succeeded',
                'payment_id' => $booking->payment?->transaction_id,
            ],
        ];
    }

    /**
     * Simulate payment completion
     */
    public function simulatePayment(string $transactionId, bool $success): void
    {
        if (!isset(self::$simulatedPayments[$transactionId])) {
            throw new \InvalidArgumentException("Transaction {$transactionId} not found");
        }

        $payment = self::$simulatedPayments[$transactionId];
        
        if ($success) {
            $this->processSuccessfulPayment($payment['booking_id'], $transactionId);
        } else {
            $this->processFailedPayment($payment['booking_id'], $transactionId);
        }
    }

    /**
     * Process successful payment
     */
    private function processSuccessfulPayment(int $bookingId, string $transactionId): void
    {
        $booking = Booking::findOrFail($bookingId);
        
        // Update booking
        $booking->update([
            'status' => 'confirmed',
            'payment_status' => PaymentStatus::Completed,
        ]);

        // Update payment record
        if ($booking->payment) {
            $booking->payment->update([
                'status' => PaymentStatus::Completed,
                'transaction_id' => $transactionId,
                'paid_at' => now(),
            ]);
        }

        // Update simulated storage
        self::$simulatedPayments[$transactionId]['status'] = 'paid';

        // TODO: Broadcast payment received event (Phase 11)
        // broadcast(new PaymentReceived($booking));
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
