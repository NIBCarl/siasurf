<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\ReceiptService;
use App\Contracts\PaymentServiceInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class PaymentController extends Controller
{
    protected PaymentServiceInterface $paymentService;
    protected ReceiptService $receiptService;

    public function __construct(
        PaymentServiceInterface $paymentService,
        ReceiptService $receiptService
    ) {
        $this->paymentService = $paymentService;
        $this->receiptService = $receiptService;
    }

    /**
     * Display payment page
     */
    public function show(Booking $booking): Response
    {
        $this->authorize('view', $booking);

        $booking->load(['instructor', 'payment']);

        return Inertia::render('Payment/Show', [
            'booking' => $booking,
        ]);
    }

    /**
     * Process GCash payment
     */
    public function processGCash(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('view', $booking);

        try {
            // Check if payment already exists
            if ($booking->payment && $booking->payment->status->value === 'completed') {
                return redirect()
                    ->route('bookings.confirmation', $booking)
                    ->with('success', 'Payment already completed!');
            }

            // Create payment intent
            $paymentIntent = $this->paymentService->createPaymentIntent($booking);

            // Update booking with transaction ID
            if ($booking->payment) {
                $booking->payment->update([
                    'transaction_id' => $paymentIntent['transaction_id'],
                ]);
            }

            // Redirect to checkout URL
            return redirect($paymentIntent['checkout_url']);

        } catch (\Exception $e) {
            Log::error('GCash payment initiation failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to initiate payment. Please try again.');
        }
    }

    /**
     * Handle cash payment (marked by instructor/admin)
     */
    public function markAsPaid(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('update', $booking);

        // Only instructor or admin can mark as paid
        if (!auth()->user()->isAdmin() && auth()->id() !== $booking->instructor_id) {
            abort(403, 'Only the assigned instructor or admin can mark cash payments.');
        }

        $validated = $request->validate([
            'amount_received' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        // Update payment status
        if ($booking->payment) {
            $booking->payment->update([
                'status' => 'completed',
                'paid_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);
        }

        // Update booking status
        $booking->update([
            'status' => 'confirmed',
            'payment_status' => 'completed',
        ]);

        // Generate receipt
        $this->receiptService->generateReceipt($booking);

        return back()->with('success', 'Payment marked as received. Receipt generated.');
    }

    /**
     * Handle payment success webhook/redirect
     */
    public function success(Request $request, Booking $booking): Response|RedirectResponse
    {
        $this->authorize('view', $booking);

        // Generate receipt if payment is completed
        if ($booking->payment && $booking->payment->isCompleted()) {
            $this->receiptService->generateReceipt($booking);
        }

        return redirect()
            ->route('bookings.confirmation', $booking)
            ->with('success', 'Payment completed successfully!');
    }

    /**
     * Handle payment cancellation
     */
    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('view', $booking);

        // Mark payment as failed
        if ($booking->payment) {
            $booking->payment->update([
                'status' => 'failed',
            ]);
        }

        return redirect()
            ->route('bookings.show', $booking)
            ->with('error', 'Payment was cancelled. You can try again.');
    }

    /**
     * Handle PayMongo webhook
     */
    public function webhook(Request $request): \Illuminate\Http\Response
    {
        try {
            $this->paymentService->handleWebhook($request);
            return response('Webhook processed', 200);
        } catch (\Exception $e) {
            Log::error('Payment webhook failed', [
                'error' => $e->getMessage(),
            ]);
            return response('Webhook failed', 400);
        }
    }

    /**
     * Download receipt PDF
     */
    public function downloadReceipt(Booking $booking)
    {
        $this->authorize('view', $booking);

        return $this->receiptService->streamReceipt($booking);
    }

    /**
     * Process refund
     */
    public function refund(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('refund', $booking->payment);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'amount' => 'nullable|numeric|min:0|max:' . $booking->total_amount,
        ]);

        try {
            $refundData = $this->paymentService->processRefund(
                $booking, 
                $validated['amount'] ?? null
            );

            // Update booking status
            $booking->update([
                'status' => 'cancelled',
                'payment_status' => 'refunded',
            ]);

            return back()->with('success', 'Refund processed successfully.');

        } catch (\Exception $e) {
            Log::error('Refund failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to process refund. ' . $e->getMessage());
        }
    }

    /**
     * Mock checkout page (for development)
     */
    public function mockCheckout(Request $request, string $transactionId)
    {
        $forceSuccess = $request->boolean('force_success', true);

        return Inertia::render('Payment/MockCheckout', [
            'transactionId' => $transactionId,
            'forceSuccess' => $forceSuccess,
        ]);
    }

    /**
     * Process mock payment (for development)
     */
    public function processMockPayment(Request $request, string $transactionId): RedirectResponse
    {
        $success = $request->boolean('success', true);

        try {
            $this->paymentService->simulatePayment($transactionId, $success);

            if ($success) {
                return redirect()->route('dashboard')->with('success', 'Mock payment successful!');
            } else {
                return redirect()->route('dashboard')->with('error', 'Mock payment failed.');
            }
        } catch (\Exception $e) {
            return redirect()->route('dashboard')->with('error', $e->getMessage());
        }
    }
}