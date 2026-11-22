<?php

namespace App\Contracts;

use App\Models\Booking;
use Illuminate\Http\Request;

interface PaymentServiceInterface
{
    /**
     * Create a payment intent for a booking
     *
     * @param Booking $booking
     * @param array $options Additional options (e.g., forceSuccess for testing)
     * @return array Payment intent data including checkout URL
     */
    public function createPaymentIntent(Booking $booking, array $options = []): array;

    /**
     * Handle payment webhook
     *
     * @param Request $request
     * @return void
     */
    public function handleWebhook(Request $request): void;

    /**
     * Verify webhook signature
     *
     * @param string $signature
     * @param string $payload
     * @return bool
     */
    public function verifySignature(string $signature, string $payload): bool;

    /**
     * Get payment status
     *
     * @param string $transactionId
     * @return string
     */
    public function getPaymentStatus(string $transactionId): string;

    /**
     * Process refund
     *
     * @param Booking $booking
     * @param float|null $amount Null for lesson price refund
     * @param string $reason PayMongo reason enum
     * @param array<string, mixed> $metadata
     * @return array
     */
    public function processRefund(
        Booking $booking,
        ?float $amount = null,
        string $reason = 'requested_by_customer',
        array $metadata = []
    ): array;

    /**
     * Simulate payment success/failure (for testing)
     *
     * @param string $transactionId
     * @param bool $success
     * @return void
     */
    public function simulatePayment(string $transactionId, bool $success): void;
}
