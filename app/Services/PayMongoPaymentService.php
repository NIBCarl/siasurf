<?php

namespace App\Services;

use App\Contracts\PaymentServiceInterface;
use App\Models\Booking;
use App\Models\Payment;
use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayMongoPaymentService implements PaymentServiceInterface
{
    protected string $secretKey;
    protected string $webhookSecret;
    protected string $baseUrl = 'https://api.paymongo.com/v1';

    public function __construct()
    {
        $this->secretKey = config('services.paymongo.secret_key', '');
        $this->webhookSecret = config('services.paymongo.webhook_secret', '');
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
        $signature = $request->header('Paymongo-Signature');

        // Verify webhook signature
        if (!$this->verifySignature($signature, $payload)) {
            Log::warning('Invalid PayMongo webhook signature');
            abort(401, 'Invalid signature');
        }

        $event = $request->input('data.attributes');

        if ($event['type'] === 'checkout.session.completed') {
            $bookingId = $event['data']['attributes']['metadata']['booking_id'] ?? null;
            $transactionId = $event['data']['id'];

            if ($bookingId) {
                $this->processSuccessfulPayment($bookingId, $transactionId);
            }
        }
    }

    /**
     * Verify PayMongo webhook signature
     */
    public function verifySignature(string $signature, string $payload): bool
    {
        if (empty($this->webhookSecret)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);
        
        return hash_equals($expectedSignature, $signature);
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
    public function processRefund(Booking $booking, ?float $amount = null): array
    {
        if (empty($this->secretKey)) {
            throw new \RuntimeException('PayMongo secret key not configured');
        }

        $payment = $booking->payment;
        if (!$payment || !$payment->transaction_id) {
            throw new \RuntimeException('No payment found for this booking');
        }

        $refundAmount = $amount ?? $booking->total_amount;

        $response = Http::withBasicAuth($this->secretKey, '')
            ->post("{$this->baseUrl}/refunds", [
                'data' => [
                    'attributes' => [
                        'amount' => (int) ($refundAmount * 100),
                        'payment_id' => $payment->transaction_id,
                        'reason' => 'Requested by customer',
                    ],
                ],
            ]);

        if (!$response->successful()) {
            Log::error('PayMongo refund failed', [
                'booking_id' => $booking->id,
                'response' => $response->json(),
            ]);
            throw new \RuntimeException('Failed to process refund');
        }

        // Update local payment record
        $payment->update([
            'status' => PaymentStatus::Refunded,
            'refunded_at' => now(),
        ]);

        return $response->json()['data'];
    }

    /**
     * Not applicable for real PayMongo service
     */
    public function simulatePayment(string $transactionId, bool $success): void
    {
        throw new \RuntimeException('Cannot simulate payments with real PayMongo service');
    }

    /**
     * Process successful payment
     */
    private function processSuccessfulPayment(int $bookingId, string $transactionId): void
    {
        $booking = Booking::findOrFail($bookingId);
        
        $booking->update([
            'status' => 'confirmed',
            'payment_status' => PaymentStatus::Completed,
        ]);

        if ($booking->payment) {
            $booking->payment->update([
                'status' => PaymentStatus::Completed,
                'transaction_id' => $transactionId,
                'paid_at' => now(),
            ]);
        }

        // TODO: Broadcast payment received event (Phase 11)
        // broadcast(new PaymentReceived($booking));
    }
}
