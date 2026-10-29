<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Services\PaymentReceiptService;
use App\Services\ReceiptService;
use App\Contracts\PaymentServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class PaymentController extends Controller
{
    protected PaymentServiceInterface $paymentService;
    protected ReceiptService $receiptService;
    protected PaymentReceiptService $receiptImages;

    public function __construct(
        PaymentServiceInterface $paymentService,
        ReceiptService $receiptService,
        PaymentReceiptService $receiptImages
    ) {
        $this->paymentService = $paymentService;
        $this->receiptService = $receiptService;
        $this->receiptImages = $receiptImages;
    }

    /**
     * Display payment page
     */
    public function show(Booking $booking): Response
    {
        $this->authorize('view', $booking);

        $booking->load(['instructor', 'payment.verifiedBy']);

        return Inertia::render('Payment/Show', [
            'booking' => $booking,
            // Upload is only meaningful for a GCash booking that is inside its
            // payment window and has not been settled yet.
            'canUploadReceipt' => $this->canUploadReceipt($booking),
            'receiptUrl' => $booking->payment?->hasReceipt()
                ? route('payments.receipt.image', $booking->id)
                : null,
            'maxReceiptMb' => (int) round(PaymentReceiptService::MAX_KILOBYTES / 1024),
        ]);
    }

    /**
     * Whether the signed-in student may (re)upload a GCash receipt.
     */
    private function canUploadReceipt(Booking $booking): bool
    {
        $studentId = auth()->id();

        if ($studentId !== $booking->student_id) {
            return false;
        }

        $payment = $booking->payment;

        if (! $payment || $payment->payment_method !== PaymentMethod::GCash) {
            return false;
        }

        if ($booking->status !== BookingStatus::AwaitingPayment) {
            return false;
        }

        if ($booking->paymentDeadlinePassed()) {
            return false;
        }

        return in_array($payment->status, [
            PaymentStatus::Pending,
            PaymentStatus::Failed,
            PaymentStatus::PendingVerification,
        ], true);
    }

    /**
     * Store a GCash receipt uploaded by the student.
     */
    public function uploadReceipt(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('view', $booking);

        $payment = $booking->payment;

        abort_if($payment === null, 404, 'This booking has no payment record yet.');

        $this->authorize('uploadReceipt', $payment);

        if ($payment->payment_method !== PaymentMethod::GCash) {
            return back()->with('error', 'Only GCash payments need a receipt. Cash is recorded by your instructor.');
        }

        if (! $this->canUploadReceipt($booking)) {
            return back()->with('error', $booking->paymentDeadlinePassed()
                ? 'The payment window has closed. Please contact SISA for help with this booking.'
                : 'This booking is not waiting for a GCash receipt right now.');
        }

        $validated = $request->validate([
            'receipt' => [
                'required',
                'image',
                'mimes:'.PaymentReceiptService::MIMES,
                'max:'.PaymentReceiptService::MAX_KILOBYTES,
            ],
            'reference' => ['nullable', 'string', 'max:100'],
        ], [
            'receipt.required' => 'Choose a screenshot of your GCash receipt.',
            'receipt.image' => 'The receipt must be an image.',
            'receipt.mimes' => 'Upload a JPG, PNG or WebP screenshot of your GCash receipt.',
            'receipt.max' => 'Receipts must be 5 MB or smaller.',
            'receipt.uploaded' => 'The receipt could not be uploaded. The server limit is lower than 5 MB — compress the image and try again.',
        ]);

        try {
            $this->receiptImages->store($payment, $validated['receipt'], $validated['reference'] ?? null);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'receipt' => 'The receipt could not be saved. Check that storage is writable and try again.',
            ]);
        }

        return back()->with(
            'success',
            'Receipt uploaded. Your instructor will verify it and confirm your booking.'
        );
    }

    /**
     * Stream the receipt image to an authorised viewer (student, instructor or
     * admin). The file is private, so this route is the only way to read it.
     */
    public function receiptImage(Booking $booking)
    {
        $this->authorize('view', $booking);

        $payment = $booking->payment;

        abort_if($payment === null || ! $payment->hasReceipt(), 404, 'No receipt has been uploaded for this booking.');

        return $this->receiptImages->response($payment);
    }

    /**
     * Approve a GCash receipt: settle the payment and confirm the booking.
     */
    public function verifyReceipt(Request $request, Booking $booking): RedirectResponse
    {
        $payment = $booking->payment;

        abort_if($payment === null, 404, 'This booking has no payment record.');

        $this->authorize('verify', $payment);

        if (! $payment->isAwaitingVerification()) {
            return back()->with('error', 'There is no receipt waiting for verification.');
        }

        $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($booking, $payment) {
            $payment->markReceiptVerified(auth()->id());

            $booking->update([
                'payment_status' => PaymentStatus::Completed,
                'payment_deadline_at' => null,
            ]);

            // The lesson was only waiting on the money: confirming it now turns
            // the held slot into a confirmed booking.
            if ($booking->status === BookingStatus::AwaitingPayment) {
                $booking->confirm();
            }
        });

        return back()->with('success', 'Receipt verified. The payment is now marked as paid.');
    }

    /**
     * Reject a GCash receipt with a reason. The student gets a fresh window to
     * upload a corrected screenshot.
     */
    public function rejectReceipt(Request $request, Booking $booking): RedirectResponse
    {
        $payment = $booking->payment;

        abort_if($payment === null, 404, 'This booking has no payment record.');

        $this->authorize('verify', $payment);

        if (! $payment->isAwaitingVerification()) {
            return back()->with('error', 'There is no receipt waiting for verification.');
        }

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ], [
            'note.required' => 'Tell the student why the receipt was rejected.',
        ]);

        DB::transaction(function () use ($booking, $payment, $validated) {
            $payment->rejectReceipt($validated['note'], auth()->id());

            $booking->update([
                'payment_status' => PaymentStatus::Pending,
                // Give the student a usable window to correct the upload.
                'payment_deadline_at' => $booking->status === BookingStatus::AwaitingPayment
                    ? now()->addMinutes(Booking::PAYMENT_HOLD_MINUTES)
                    : $booking->payment_deadline_at,
            ]);
        });

        return back()->with('success', 'Receipt rejected. The student can upload a corrected one.');
    }

    /**
     * Process GCash payment
     */
    public function processGCash(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('view', $booking);

        if ($booking->payment && $booking->payment->status->value === 'completed') {
            return redirect()
                ->route('bookings.confirmation', $booking)
                ->with('success', 'Payment already completed!');
        }

        if ($booking->status !== \App\Enums\BookingStatus::AwaitingPayment) {
            if ($booking->status === \App\Enums\BookingStatus::Confirmed) {
                return redirect()->route('bookings.confirmation', $booking)
                    ->with('success', 'This booking is already confirmed.');
            }

            return back()->with('error', 'Payment is only available after the instructor accepts your booking.');
        }

        if ($booking->paymentDeadlinePassed()) {
            \App\Jobs\ExpirePaymentWindowJob::dispatchSync($booking);

            return redirect()->route('student.bookings.show', $booking)
                ->with('error', 'The payment window has expired. The slot was released; please rebook or contact SISA.');
        }

        if ($booking->payment?->payment_method === \App\Enums\PaymentMethod::Cash) {
            return back()->with('error', 'This booking is set to cash payment. Pay the instructor at the beach.');
        }

        // A receipt that is already under review must not be paid twice.
        if ($booking->payment?->isAwaitingVerification()) {
            return back()->with('info', 'Your GCash receipt is already waiting for verification.');
        }

        try {
            // The acceptance flow already created a checkout session; reuse it
            // instead of creating a second payment intent.
            if ($booking->payment?->checkout_url) {
                return redirect($booking->payment->checkout_url);
            }

            $paymentIntent = $this->paymentService->createPaymentIntent($booking);

            if ($booking->payment) {
                $booking->payment->update([
                    'transaction_id' => $paymentIntent['transaction_id'],
                    'checkout_url' => $paymentIntent['checkout_url'],
                ]);
            }

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

        // Guard against resurrecting a cancelled, declined or already-paid
        // booking through the cash path.
        if (!$booking->canRecordCashPayment()) {
            return back()->with('error', 'Cash can only be recorded for a confirmed or live lesson that is still unpaid.');
        }

        // Update payment status
        if ($booking->payment) {
            $booking->payment->update([
                'status' => 'completed',
                'paid_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);
        } else {
            // A cash booking may predate its payment row; create one so the
            // record matches what was actually collected.
            $booking->payment()->create([
                'amount' => $booking->total_amount,
                'payment_method' => \App\Enums\PaymentMethod::Cash,
                'status' => \App\Enums\PaymentStatus::Completed,
                'paid_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);
        }

        // Update booking status - only the payment side, never the lifecycle.
        $booking->update([
            'payment_status' => \App\Enums\PaymentStatus::Completed,
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
    public function mockCheckout(Request $request, string $transactionId): Response|RedirectResponse
    {
        $payment = Payment::where('transaction_id', $transactionId)->firstOrFail();
        $booking = $payment->booking;

        abort_if($booking === null, 404);

        // The mock checkout is still a real payment surface: only the student
        // who owns the booking may open it.
        $this->authorize('view', $booking);

        if ($booking->status !== BookingStatus::AwaitingPayment) {
            return redirect()->route('student.bookings.show', $booking)
                ->with('error', 'This payment is no longer pending.');
        }

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

        $payment = Payment::where('transaction_id', $transactionId)->firstOrFail();
        $booking = $payment->booking;

        abort_if($booking === null, 404);

        // Never let a guest, an unrelated student, or a stale session mutate
        // somebody else's booking through a mock transaction id.
        $this->authorize('view', $booking);

        if ($booking->status !== BookingStatus::AwaitingPayment) {
            return redirect()->route('student.bookings.show', $booking)
                ->with('error', 'This payment is no longer pending.');
        }

        try {
            $this->paymentService->simulatePayment($transactionId, $success);

            $payment->refresh();
            $booking = $payment->booking;

            if ($success) {
                return redirect()->route('bookings.confirmation', $booking->id)
                    ->with('success', 'Payment completed successfully!');
            }

            return redirect()->route('student.bookings.show', $booking->id)
                ->with('error', 'Payment failed. You can try again.');
        } catch (\Exception $e) {
            return redirect()->route('student.bookings.show', $booking->id)
                ->with('error', $e->getMessage());
        }
    }
}