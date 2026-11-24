<?php

namespace App\Services;

use App\Contracts\PaymentServiceInterface;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Events\RefundStatusUpdated;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\RefundStatusNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayMongoPaymentService implements PaymentServiceInterface
{
    protected string $secretKey;
    protected string $webhookSecret;
    protected string $mode;
    protected string $baseUrl = 'https://api.paymongo.com/v1';

    public function __construct()
    {
        $this->secretKey = (string) config('services.paymongo.secret_key', '');
        $this->webhookSecret = (string) config('services.paymongo.webhook_secret', '');
        $this->mode = (string) config('services.paymongo.mode', 'test');
    }

    /**
     * Create a GCash payment intent
     */
    public function createPaymentIntent(Booking $booking, array $options = []): array
    {
        if (empty($this->secretKey)) {
            throw new \RuntimeException('PayMongo secret key not configured');
        }

        $response = Http::withBasicAuth($this->secretKey, '')
            ->post("{$this->baseUrl}/payment_intents", [
                'data' => [
                    'attributes' => [
                        'amount' => (int) ($booking->total_amount * 100), // Convert to cents
                        'currency' => 'PHP',
                        'description' => "Surf lesson booking #{$booking->id}",
                        'metadata' => [
                            'booking_id' => $booking->id,
                            'student_id' => $booking->student_id,
                            'instructor_id' => $booking->instructor_id,
                        ],
                    ],
                ],
            ]);

        if (!$response->successful()) {
            Log::error('PayMongo payment intent creation failed', [
                'booking_id' => $booking->id,
                'response' => $response->json(),
            ]);
            throw new \RuntimeException('Failed to create payment intent');
        }

        $data = $response->json()['data'];

        // Create checkout session for GCash
        $checkoutResponse = Http::withBasicAuth($this->secretKey, '')
            ->post("{$this->baseUrl}/checkout_sessions", [
                'data' => [
                    'attributes' => [
                        'billing' => [
                            'name' => $booking->student->name,
                            'email' => $booking->student->email,
                        ],
                        'line_items' => [
                            [
                                'amount' => (int) ($booking->total_amount * 100),
                                'currency' => 'PHP',
                                'name' => 'Surf Lesson',
                                'quantity' => 1,
                            ],
                        ],
                        'payment_method_types' => ['gcash'],
                        'success_url' => route('payments.success', ['booking' => $booking->id]),
                        'cancel_url' => route('payments.cancel', ['booking' => $booking->id]),
                        'metadata' => [
                            'booking_id' => $booking->id,
                        ],
                    ],
                ],
            ]);

        if (!$checkoutResponse->successful()) {
            Log::error('PayMongo checkout session creation failed', [
                'booking_id' => $booking->id,
                'response' => $checkoutResponse->json(),
            ]);
            throw new \RuntimeException('Failed to create checkout session');
        }

        $checkoutData = $checkoutResponse->json()['data'];

        return [
            'id' => $data['id'],
            'type' => $data['type'],
            'attributes' => $data['attributes'],
            'checkout_url' => $checkoutData['attributes']['checkout_url'],
            'transaction_id' => $data['id'],
        ];
    }

    /**
     * Handle PayMongo webhook
     */
    public function handleWebhook(Request $request): void
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Paymongo-Signature', '');

        // Verify webhook signature
        if (! $this->verifySignature($signature, $payload)) {
            Log::warning('Invalid PayMongo webhook signature');
            abort(401, 'Invalid signature');
        }

        $event = $request->input('data.attributes', []);
        $eventType = $event['type'] ?? null;

        if ($eventType === 'checkout.session.completed') {
            $sessionData = $event['data'] ?? [];
            $bookingId = $sessionData['attributes']['metadata']['booking_id'] ?? null;
            $transactionId = $sessionData['id'] ?? null;
            $payments = $sessionData['attributes']['payments'] ?? [];
            $gatewayPaymentId = $payments[0]['id'] ?? null;

            if ($bookingId && $transactionId) {
                $this->processSuccessfulPayment(
                    (int) $bookingId,
                    (string) $transactionId,
                    $gatewayPaymentId ? (string) $gatewayPaymentId : null
                );
            }

            return;
        }

        if (in_array($eventType, ['payment.refund.updated', 'payment.refunded'], true)) {
            $this->handleRefundWebhook($event['data'] ?? []);
        }
    }

    /**
     * Verify PayMongo webhook signature (`t=...,te=...,li=...`).
     */
    public function verifySignature(string $signature, string $payload): bool
    {
        if (empty($this->webhookSecret) || empty($signature)) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signature) as $segment) {
            $pair = explode('=', trim($segment), 2);
            if (count($pair) === 2) {
                $parts[trim($pair[0])] = trim($pair[1]);
            }
        }

        $timestamp = $parts['t'] ?? '';
        if ($timestamp === '' || ! ctype_digit((string) $timestamp)) {
            return false;
        }

        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            return false;
        }

        $expectedSignature = $this->mode === 'live'
            ? ($parts['li'] ?? '')
            : ($parts['te'] ?? $parts['li'] ?? '');

        if ($expectedSignature === '') {
            return false;
        }

        $computed = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->webhookSecret);

        return hash_equals($expectedSignature, $computed);
    }

    /**
     * Get payment status from PayMongo
     */
    public function getPaymentStatus(string $transactionId): string
    {
        if (empty($this->secretKey)) {
            return 'unknown';
        }

        $response = Http::withBasicAuth($this->secretKey, '')
            ->get("{$this->baseUrl}/payment_intents/{$transactionId}");

        if (!$response->successful()) {
            return 'unknown';
        }

        $data = $response->json()['data'];
        return $data['attributes']['status'];
    }

    /**
     * Process refund via PayMongo
     */
    public function processRefund(
        Booking $booking,
        ?float $amount = null,
        string $reason = 'requested_by_customer',
        array $metadata = []
    ): array {
        if (empty($this->secretKey)) {
            throw new \RuntimeException('PayMongo secret key not configured');
        }

        $payment = $booking->payment;
        $paymentId = $payment?->gateway_payment_id ?: $payment?->transaction_id;

        if (! $payment || ! $paymentId) {
            throw new \RuntimeException('No PayMongo payment reference found for this booking');
        }

        $refundAmount = $amount ?? $booking->effectiveLessonPrice();
        $allowedReasons = ['requested_by_customer', 'duplicate', 'fraudulent', 'others'];
        $paymongoReason = in_array($reason, $allowedReasons, true) ? $reason : 'requested_by_customer';

        $attributes = [
            'amount' => (int) round($refundAmount * 100),
            'payment_id' => $paymentId,
            'reason' => $paymongoReason,
        ];

        if (! empty($metadata)) {
            $attributes['metadata'] = $metadata;
        }

        $response = Http::withBasicAuth($this->secretKey, '')
            ->post("{$this->baseUrl}/refunds", [
                'data' => [
                    'attributes' => $attributes,
                ],
            ]);

        if (! $response->successful()) {
            Log::error('PayMongo refund failed', [
                'booking_id' => $booking->id,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            $detail = $response->json('errors.0.detail') ?? 'Failed to process PayMongo refund';
            throw new \RuntimeException($detail);
        }

        return $response->json('data') ?? [];
    }

    /**
     * Simulate payment completion (dev/testing only)
     */
    public function simulatePayment(string $transactionId, bool $success): void
    {
        $payment = Payment::where('transaction_id', $transactionId)->first();

        if (!$payment) {
            throw new \InvalidArgumentException("Transaction {$transactionId} not found");
        }

        if ($success) {
            $this->processSuccessfulPayment($payment->booking_id, $transactionId, $transactionId);
        } else {
            $payment->update([
                'status' => PaymentStatus::Failed,
            ]);
        }
    }

    /**
     * Process successful payment
     */
    private function processSuccessfulPayment(int $bookingId, string $transactionId, ?string $gatewayPaymentId = null): void
    {
        $booking = DB::transaction(function () use ($bookingId, $transactionId, $gatewayPaymentId) {
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
                Log::warning('Ignoring late/invalid PayMongo payment success webhook.', [
                    'booking_id' => $locked->id,
                    'status' => $locked->status?->value,
                    'transaction_id' => $transactionId,
                ]);

                return null;
            }

            $locked->update([
                'status' => 'confirmed',
                'payment_status' => PaymentStatus::Completed,
                'payment_deadline_at' => null,
            ]);

            if ($locked->payment) {
                $existingTx = (string) ($locked->payment->transaction_id ?? '');
                $resolvedTx = str_starts_with($existingTx, 'pi_') ? $existingTx : $transactionId;

                $locked->payment->update([
                    'status' => PaymentStatus::Completed,
                    'transaction_id' => $resolvedTx,
                    'gateway_payment_id' => $gatewayPaymentId ?? $locked->payment->gateway_payment_id,
                    'paid_at' => $locked->payment->paid_at ?? now(),
                ]);
            }

            return $locked->fresh(['student', 'instructor', 'surfSpot', 'payment']);
        });

        if (! $booking) {
            return;
        }

        // Phase 11 - broadcast so the student's booking page and the admin
        // dashboard update without a refresh.
        $eventService = app(BookingEventService::class);
        if ($booking->payment) {
            $eventService->recordPaymentCompleted($booking, $booking->payment->fresh(), $booking->student_id);
        }
        $eventService->recordConfirmed($booking, $booking->student_id, 'GCash');

        try {
            if ($booking->payment) {
                broadcast(new \App\Events\PaymentReceived($booking->payment->fresh()))->toOthers();
            }

            broadcast(new \App\Events\BookingConfirmed($booking))->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('Failed to broadcast payment received.', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }

        app(ReceiptService::class)->sendPaymentReceipts($booking);
    }

    /**
     * Transition a Refund record idempotently when PayMongo confirms or fails a refund.
     *
     * @param array<string, mixed> $refundData
     */
    private function handleRefundWebhook(array $refundData): void
    {
        $paymongoRefundId = $refundData['id'] ?? null;
        $attributes = $refundData['attributes'] ?? [];
        $status = strtolower((string) ($attributes['status'] ?? ''));
        $refundIdMeta = $attributes['metadata']['refund_id'] ?? null;

        $refund = null;
        if ($paymongoRefundId) {
            $refund = Refund::where('paymongo_refund_id', $paymongoRefundId)->first();
        }
        if (! $refund && $refundIdMeta) {
            $refund = Refund::find((int) $refundIdMeta);
        }

        if (! $refund) {
            Log::warning('PayMongo refund webhook received for unknown refund.', [
                'paymongo_refund_id' => $paymongoRefundId,
                'metadata_refund_id' => $refundIdMeta,
            ]);

            return;
        }

        $changed = DB::transaction(function () use ($refund, $refundData, $paymongoRefundId, $attributes, $status) {
            $locked = Refund::with(['booking', 'payment'])
                ->whereKey($refund->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            $now = now();

            if ($status === 'succeeded') {
                if ($locked->isCompleted()) {
                    return false;
                }

                $locked->update([
                    'status' => RefundStatus::Completed,
                    'paymongo_refund_id' => $paymongoRefundId ?: $locked->paymongo_refund_id,
                    'gateway_payload' => $refundData,
                    'processed_at' => $locked->processed_at ?? $now,
                    'completed_at' => $now,
                    'failure_reason' => null,
                ]);

                $locked->payment?->update([
                    'status' => PaymentStatus::Refunded,
                    'refunded_at' => $now,
                ]);

                $locked->booking?->update([
                    'payment_status' => PaymentStatus::Refunded,
                ]);

                return true;
            }

            if (in_array($status, ['pending', 'processing'], true)) {
                if (in_array($locked->status, [RefundStatus::Processing, RefundStatus::Completed], true)) {
                    return false;
                }

                $locked->update([
                    'status' => RefundStatus::Processing,
                    'paymongo_refund_id' => $paymongoRefundId ?: $locked->paymongo_refund_id,
                    'gateway_payload' => $refundData,
                    'processed_at' => $locked->processed_at ?? $now,
                ]);

                $locked->payment?->update([
                    'status' => PaymentStatus::RefundProcessing,
                ]);

                $locked->booking?->update([
                    'payment_status' => PaymentStatus::RefundProcessing,
                ]);

                return true;
            }

            if ($status === 'failed' && ! $locked->isCompleted()) {
                $locked->update([
                    'status' => RefundStatus::Failed,
                    'paymongo_refund_id' => $paymongoRefundId ?: $locked->paymongo_refund_id,
                    'gateway_payload' => $refundData,
                    'failed_at' => $now,
                    'failure_reason' => (string) ($attributes['failure_reason'] ?? 'PayMongo refund failed.'),
                ]);

                return true;
            }

            return false;
        });

        if (! $changed) {
            return;
        }

        $fresh = $refund->fresh(['booking.student', 'booking.instructor', 'booking.surfSpot']);
        if (! $fresh) {
            return;
        }

        try {
            broadcast(new RefundStatusUpdated($fresh))->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('Failed to broadcast refund webhook update.', [
                'refund_id' => $fresh->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $fresh->booking?->student?->notify(new RefundStatusNotification($fresh));

        if ($fresh->status === RefundStatus::Failed) {
            User::query()->admins()->get()->each(
                fn (User $admin) => $admin->notify(new RefundStatusNotification($fresh))
            );
        }
    }
}
