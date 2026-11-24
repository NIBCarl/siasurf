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
use Illuminate\Support\Str;

class XenditPaymentService implements PaymentServiceInterface
{
    protected string $secretKey;
    protected string $webhookToken;
    protected string $callbackUrl;
    protected string $baseUrl = 'https://api.xendit.co';

    public function __construct()
    {
        $this->secretKey = (string) config('services.xendit.secret_key', '');
        $this->webhookToken = (string) config('services.xendit.webhook_token', '');
        $this->callbackUrl = (string) config('services.xendit.callback_url', '');
    }

    /**
     * Create a Xendit GCash eWallet charge.
     */
    public function createPaymentIntent(Booking $booking, array $options = []): array
    {
        if (empty($this->secretKey)) {
            throw new \RuntimeException('Xendit secret key not configured');
        }

        $booking->loadMissing('student');

        $amount = round((float) $booking->total_amount, 2);
        $referenceId = sprintf('booking-%d-%d-%s', $booking->id, now()->timestamp, Str::lower(Str::random(4)));

        $response = $this->http()
            ->withHeaders([
                'callback-url' => $this->resolveCallbackUrl(),
            ])
            ->post("{$this->baseUrl}/ewallets/charges", [
                'reference_id' => $referenceId,
                'currency' => 'PHP',
                'amount' => $amount,
                'checkout_method' => 'ONE_TIME_PAYMENT',
                'channel_code' => 'PH_GCASH',
                'channel_properties' => [
                    'success_redirect_url' => route('payments.success', ['booking' => $booking->id]),
                    'failure_redirect_url' => route('payments.cancel', ['booking' => $booking->id]),
                ],
                'metadata' => [
                    'booking_id' => (string) $booking->id,
                    'student_id' => (string) $booking->student_id,
                    'instructor_id' => (string) $booking->instructor_id,
                ],
            ]);

        if (! $response->successful()) {
            Log::error('Xendit GCash charge creation failed', [
                'booking_id' => $booking->id,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            $message = $response->json('message') ?? 'Failed to create Xendit GCash charge';
            throw new \RuntimeException((string) $message);
        }

        $data = $response->json() ?? [];
        $chargeId = (string) ($data['id'] ?? '');
        $checkoutUrl = $data['actions']['desktop_web_checkout_url']
            ?? $data['actions']['mobile_web_checkout_url']
            ?? null;

        if ($chargeId === '' || empty($checkoutUrl)) {
            throw new \RuntimeException('Xendit did not return a valid GCash checkout URL');
        }

        return [
            'id' => $chargeId,
            'type' => 'ewallet_charge',
            'attributes' => $data,
            'checkout_url' => (string) $checkoutUrl,
            'transaction_id' => $chargeId,
        ];
    }

    /**
     * Handle Xendit webhook callbacks (eWallet payment status and refund status).
     */
    public function handleWebhook(Request $request): void
    {
        $payload = $request->getContent();
        $token = (string) ($request->header('x-callback-token')
            ?: $request->header('X-Callback-Token')
            ?: $request->header('Paymongo-Signature', ''));

        if (! $this->verifySignature($token, $payload)) {
            Log::warning('Invalid Xendit webhook callback token');
            abort(401, 'Invalid webhook token');
        }

        $event = (string) $request->input('event', '');
        $data = $request->input('data');

        // Xendit eWallet webhooks may either wrap the payload inside `data`
        // with an `event` key or post the charge/refund resource at the root.
        $resource = is_array($data) ? $data : $request->all();
        $resourceId = (string) ($resource['id'] ?? $resource['refund_id'] ?? $resource['payment_id'] ?? '');
        $status = strtoupper((string) ($resource['status'] ?? ''));

        $isRefundEvent = str_contains(strtolower($event), 'refund')
            || str_starts_with($resourceId, 'ewr_')
            || str_starts_with($resourceId, 'rfd-')
            || isset($resource['charge_id'])
            || isset($resource['refund_amount']);

        if ($isRefundEvent) {
            $this->handleRefundWebhook($resource);

            return;
        }

        if (in_array($status, ['SUCCEEDED', 'COMPLETED', 'PAID'], true)) {
            $bookingId = $this->resolveBookingIdFromWebhook($resource, $resourceId);

            if ($bookingId && $resourceId !== '') {
                $this->processSuccessfulPayment($bookingId, $resourceId, $resourceId);
            }
        }
    }

    /**
     * Verify Xendit's `x-callback-token` header.
     */
    public function verifySignature(string $signature, string $payload): bool
    {
        if ($this->webhookToken === '') {
            // Only allow unsigned synthetic webhooks during automated tests when
            // no token header was supplied; local/dev servers must reject missing
            // or mismatched callback tokens so unauthenticated callers cannot mark
            // bookings paid.
            return app()->environment('testing') && $signature === '';
        }

        if ($signature === '') {
            return false;
        }

        return hash_equals($this->webhookToken, $signature);
    }

    /**
     * Fetch the current charge status directly from Xendit (`succeeded`, `pending`, `failed`).
     */
    public function getPaymentStatus(string $transactionId): string
    {
        if (empty($this->secretKey) || $transactionId === '') {
            return 'unknown';
        }

        $response = $this->http()
            ->get("{$this->baseUrl}/ewallets/charges/{$transactionId}");

        if (! $response->successful()) {
            return 'unknown';
        }

        return strtolower((string) ($response->json('status') ?? 'unknown'));
    }

    /**
     * Process a full or partial GCash refund via Xendit's eWallet Refunds API.
     */
    public function processRefund(
        Booking $booking,
        ?float $amount = null,
        string $reason = 'requested_by_customer',
        array $metadata = []
    ): array {
        if (empty($this->secretKey)) {
            throw new \RuntimeException('Xendit secret key not configured');
        }

        $payment = $booking->payment;
        $chargeId = $payment?->gateway_payment_id ?: $payment?->transaction_id;

        if (! $payment || ! $chargeId) {
            throw new \RuntimeException('No Xendit charge reference found for this booking');
        }

        $refundAmount = round((float) ($amount ?? $booking->effectiveLessonPrice()), 2);

        $xenditReason = match (strtolower($reason)) {
            'duplicate' => 'DUPLICATE',
            'fraudulent' => 'FRAUDULENT',
            'cancellation' => 'CANCELLATION',
            'others' => 'OTHERS',
            default => 'REQUESTED_BY_CUSTOMER',
        };

        $body = [
            'amount' => $refundAmount,
            'reason' => $xenditReason,
        ];

        $cleanMetadata = array_map(
            'strval',
            array_filter($metadata, static fn ($value) => $value !== null)
        );
        if (! empty($cleanMetadata)) {
            $body['metadata'] = $cleanMetadata;
        }

        $response = $this->http()
            ->withHeaders([
                'callback-url' => $this->resolveCallbackUrl(),
            ])
            ->post("{$this->baseUrl}/ewallets/charges/{$chargeId}/refunds", $body);

        if (! $response->successful()) {
            Log::error('Xendit refund failed', [
                'booking_id' => $booking->id,
                'charge_id' => $chargeId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            $detail = $response->json('message') ?? 'Failed to process Xendit refund';
            throw new \RuntimeException((string) $detail);
        }

        $data = $response->json() ?? [];
        $rawStatus = strtolower((string) ($data['status'] ?? 'pending'));
        $normalizedStatus = match ($rawStatus) {
            'succeeded', 'completed' => 'succeeded',
            'failed', 'cancelled' => 'failed',
            default => 'pending',
        };

        return [
            'id' => $data['id'] ?? null,
            'type' => 'refund',
            'attributes' => [
                'status' => $normalizedStatus,
                'amount' => (int) round($refundAmount * 100),
                'currency' => $data['currency'] ?? 'PHP',
                'payment_id' => $chargeId,
                'reason' => $xenditReason,
                'failure_reason' => $data['failure_code'] ?? null,
                'raw' => $data,
            ],
        ];
    }

    /**
     * Simulate payment completion (dev/testing helper).
     */
    public function simulatePayment(string $transactionId, bool $success): void
    {
        $payment = Payment::where('transaction_id', $transactionId)->first();

        if (! $payment) {
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
     * Build an authenticated HTTP client for Xendit API requests.
     * Disables cURL SSL peer verification in local/dev environments to avoid
     * Windows PHP `cURL error 60` when `curl.cainfo` is not configured.
     */
    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withBasicAuth($this->secretKey, '')
            ->withOptions([
                'verify' => ! app()->environment('local', 'development', 'testing'),
            ]);
    }

    /**
     * Resolve the webhook callback URL sent to Xendit in the `callback-url` header.
     * Xendit rejects `localhost` / `127.0.0.1` callback URLs when none is set in
     * the Xendit Dashboard, so we fall back to a valid placeholder domain during
     * pure localhost development while relying on redirect status verification.
     */
    protected function resolveCallbackUrl(): string
    {
        if ($this->callbackUrl !== '') {
            return $this->callbackUrl;
        }

        $appWebhookUrl = route('payments.webhook');
        $host = (string) parse_url($appWebhookUrl, PHP_URL_HOST);

        if (in_array($host, ['localhost', '127.0.0.1', '::1', ''], true)) {
            return 'https://example.com/webhooks/payments';
        }

        return $appWebhookUrl;
    }

    /**
     * Resolve internal booking ID from Xendit webhook metadata, reference_id, or stored transaction_id.
     *
     * @param array<string, mixed> $resource
     */
    private function resolveBookingIdFromWebhook(array $resource, string $chargeId): ?int
    {
        $metaBookingId = $resource['metadata']['booking_id'] ?? null;
        if (is_numeric($metaBookingId)) {
            return (int) $metaBookingId;
        }

        $referenceId = (string) ($resource['reference_id'] ?? '');
        if (preg_match('/^booking-(\d+)-/', $referenceId, $matches)) {
            return (int) $matches[1];
        }

        if ($chargeId !== '') {
            $bookingId = Payment::where('transaction_id', $chargeId)
                ->orWhere('gateway_payment_id', $chargeId)
                ->value('booking_id');

            if ($bookingId) {
                return (int) $bookingId;
            }
        }

        return null;
    }

    /**
     * Mark booking & payment confirmed/completed and broadcast real-time updates.
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
                Log::warning('Ignoring late/invalid Xendit payment success webhook.', [
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
                $locked->payment->update([
                    'status' => PaymentStatus::Completed,
                    'transaction_id' => $transactionId,
                    'gateway_payment_id' => $gatewayPaymentId ?? $locked->payment->gateway_payment_id ?? $transactionId,
                    'paid_at' => $locked->payment->paid_at ?? now(),
                ]);
            }

            return $locked->fresh(['student', 'instructor', 'surfSpot', 'payment']);
        });

        if (! $booking) {
            return;
        }

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
            Log::warning('Failed to broadcast Xendit payment received.', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }

        app(ReceiptService::class)->sendPaymentReceipts($booking);
    }

    /**
     * Transition a Refund record idempotently when Xendit confirms or fails a refund.
     *
     * @param array<string, mixed> $refundData
     */
    private function handleRefundWebhook(array $refundData): void
    {
        $xenditRefundId = (string) ($refundData['id'] ?? $refundData['refund_id'] ?? '');
        $chargeId = (string) ($refundData['charge_id'] ?? $refundData['payment_id'] ?? '');
        $status = strtolower((string) ($refundData['status'] ?? ''));
        $refundIdMeta = $refundData['metadata']['refund_id'] ?? null;

        $refund = null;
        if ($xenditRefundId !== '') {
            $refund = Refund::where('paymongo_refund_id', $xenditRefundId)->first();
        }
        if (! $refund && is_numeric($refundIdMeta)) {
            $refund = Refund::find((int) $refundIdMeta);
        }
        if (! $refund && $chargeId !== '') {
            $refund = Refund::where('paymongo_payment_id', $chargeId)->latest()->first();
        }

        if (! $refund) {
            Log::warning('Xendit refund webhook received for unknown refund.', [
                'xendit_refund_id' => $xenditRefundId,
                'charge_id' => $chargeId,
                'metadata_refund_id' => $refundIdMeta,
            ]);

            return;
        }

        $changed = DB::transaction(function () use ($refund, $refundData, $xenditRefundId, $status) {
            $locked = Refund::with(['booking', 'payment'])
                ->whereKey($refund->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            $now = now();

            if (in_array($status, ['succeeded', 'completed'], true)) {
                if ($locked->isCompleted()) {
                    return false;
                }

                $locked->update([
                    'status' => RefundStatus::Completed,
                    'paymongo_refund_id' => $xenditRefundId !== '' ? $xenditRefundId : $locked->paymongo_refund_id,
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
                    'paymongo_refund_id' => $xenditRefundId !== '' ? $xenditRefundId : $locked->paymongo_refund_id,
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

            if (in_array($status, ['failed', 'cancelled'], true) && ! $locked->isCompleted()) {
                $locked->update([
                    'status' => RefundStatus::Failed,
                    'paymongo_refund_id' => $xenditRefundId !== '' ? $xenditRefundId : $locked->paymongo_refund_id,
                    'gateway_payload' => $refundData,
                    'failed_at' => $now,
                    'failure_reason' => (string) ($refundData['failure_code'] ?? $refundData['failure_reason'] ?? 'Xendit refund failed.'),
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
            Log::warning('Failed to broadcast Xendit refund webhook update.', [
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
