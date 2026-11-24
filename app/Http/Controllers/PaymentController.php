<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Notifications\CashUnderpaymentFlaggedNotification;
use App\Notifications\GcashReceiptRejectedNotification;
use App\Notifications\GcashReceiptUploadedNotification;
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

        try {
            $fresh = $booking->fresh(['student', 'instructor', 'surfSpot', 'payment']);
            if ($fresh) {
                $notification = new GcashReceiptUploadedNotification($fresh);
                $fresh->instructor?->notify($notification);

                User::query()->admins()->get()->each(
                    fn (User $admin) => $admin->notify($notification)
                );
            }
        } catch (\Throwable $exception) {
            Log::warning('Failed to send GCash receipt upload notification.', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
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

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $verifierId = auth()->id();

        $error = DB::transaction(function () use ($booking, $payment, $validated, $verifierId): ?string {
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $lockedPayment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedBooking->status !== BookingStatus::AwaitingPayment) {
                return 'This booking is no longer awaiting payment verification.';
            }

            if (! $lockedPayment->isAwaitingVerification()) {
                return 'There is no receipt waiting for verification.';
            }

            $lockedPayment->markReceiptVerified($verifierId, $validated['note'] ?? null);

            $lockedBooking->update([
                'payment_status' => PaymentStatus::Completed,
                'payment_deadline_at' => null,
            ]);

            $eventService = app(\App\Services\BookingEventService::class);
            $eventService->recordPaymentCompleted($lockedBooking, $lockedPayment->fresh(), $verifierId);

            // The lesson was only waiting on the money: confirming it now turns
            // the held slot into a confirmed booking.
            $lockedBooking->confirm();
            $eventService->recordConfirmed($lockedBooking, $verifierId, 'GCash receipt');

            DB::afterCommit(function () use ($lockedBooking, $verifierId) {
                $fresh = $lockedBooking->fresh(['student', 'instructor', 'surfSpot', 'payment']);
                if (! $fresh) {
                    return;
                }

                try {
                    if ($fresh->payment) {
                        broadcast(new \App\Events\PaymentReceived($fresh->payment))->toOthers();
                    }
                    broadcast(new \App\Events\BookingConfirmed($fresh))->toOthers();
                } catch (\Throwable $exception) {
                    Log::warning('Failed to broadcast receipt verification.', [
                        'booking_id' => $fresh->id,
                        'error' => $exception->getMessage(),
                    ]);
                }

                $this->receiptService->sendPaymentReceipts($fresh);
            });

            return null;
        });

        if ($error !== null) {
            return back()->with('error', $error);
        }

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

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ], [
            'note.required' => 'Tell the student why the receipt was rejected.',
        ]);

        $error = DB::transaction(function () use ($booking, $payment, $validated): ?string {
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $lockedPayment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedBooking->status !== BookingStatus::AwaitingPayment) {
                return 'This booking is no longer awaiting payment verification.';
            }

            if (! $lockedPayment->isAwaitingVerification()) {
                return 'There is no receipt waiting for verification.';
            }

            $lockedPayment->rejectReceipt($validated['note'], auth()->id());

            $lockedBooking->update([
                'payment_status' => PaymentStatus::Pending,
                // Give the student a usable window to correct the upload, clamped
                // so it never extends past the lesson start cutoff.
                'payment_deadline_at' => $lockedBooking->paymentDeadlineFrom(),
            ]);

            return null;
        });

        if ($error !== null) {
            return back()->with('error', $error);
        }

        try {
            $fresh = $booking->fresh(['student', 'instructor', 'payment']);
            $fresh?->student?->notify(new GcashReceiptRejectedNotification($fresh, $validated['note']));
        } catch (\Throwable $exception) {
            Log::warning('Failed to send GCash receipt rejected notification.', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Receipt rejected. The student can upload a corrected one.');
    }

    /**
     * Process GCash payment
     */
    public function processGCash(Request $request, Booking $booking): \Symfony\Component\HttpFoundation\Response
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
            // unless the previous attempt failed or was cancelled.
            if (
                $booking->payment?->checkout_url
                && $booking->payment->status !== PaymentStatus::Failed
            ) {
                return $this->redirectToCheckout($request, $booking->payment->checkout_url);
            }

            $paymentIntent = $this->paymentService->createPaymentIntent($booking);

            if ($booking->payment) {
                $booking->payment->update([
                    'status' => PaymentStatus::Pending,
                    'transaction_id' => $paymentIntent['transaction_id'],
                    'checkout_url' => $paymentIntent['checkout_url'],
                ]);
            }

            return $this->redirectToCheckout($request, $paymentIntent['checkout_url']);
        } catch (\Exception $e) {
            Log::error('GCash payment initiation failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to initiate payment. Please try again.');
        }
    }

    /**
     * Perform a full-page browser navigation when redirecting to an external
     * gateway (Xendit / PayMongo) from an Inertia XHR visit to avoid CORS errors.
     */
    private function redirectToCheckout(Request $request, string $checkoutUrl): \Symfony\Component\HttpFoundation\Response
    {
        $targetHost = parse_url($checkoutUrl, PHP_URL_HOST);
        $isExternal = is_string($targetHost)
            && $targetHost !== ''
            && strcasecmp($targetHost, $request->getHost()) !== 0;

        if ($isExternal && $request->header('X-Inertia')) {
            return Inertia::location($checkoutUrl);
        }

        return redirect()->away($checkoutUrl);
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

        $totalDue = round((float) $booking->total_amount, 2);
        $rawAmount = is_numeric($request->input('amount_received'))
            ? round((float) $request->input('amount_received'), 2)
            : 0.0;
        $isUnderpaid = $rawAmount < $totalDue;

        $validated = $request->validate(
            [
                'amount_received' => 'required|numeric|min:0',
                'notes' => $isUnderpaid ? 'required|string|min:5|max:500' : 'nullable|string|max:500',
            ],
            [
                'notes.required' => 'Please provide a brief note explaining why the student underpaid so SISA Admin can review this violation.',
                'notes.min' => 'Please provide at least 5 characters explaining the underpayment.',
            ]
        );

        // Guard against resurrecting a cancelled, declined or already-paid
        // booking through the cash path.
        if (!$booking->canRecordCashPayment()) {
            return back()->with('error', 'Cash can only be recorded for a confirmed or live lesson that is not yet paid in full.');
        }

        $amountReceived = round((float) $validated['amount_received'], 2);
        $shortfall = max(0.0, round($totalDue - $amountReceived, 2));
        $changeReturned = max(0.0, round($amountReceived - $totalDue, 2));

        $targetStatus = $isUnderpaid
            ? \App\Enums\PaymentStatus::Underpaid
            : \App\Enums\PaymentStatus::Completed;

        // Store the actual collected amount when underpaid (e.g. 700 of 800),
        // or the lesson total when paid in full / cash tendered with change.
        $recordedAmount = $isUnderpaid ? $amountReceived : $totalDue;

        $noteParts = [];
        if (!empty($validated['notes'])) {
            $noteParts[] = trim($validated['notes']);
        }
        if ($isUnderpaid) {
            $noteParts[] = sprintf(
                '[UNDERPAID: Collected ₱%s of ₱%s · Shortfall owed: ₱%s]',
                number_format($amountReceived, 2),
                number_format($totalDue, 2),
                number_format($shortfall, 2)
            );
        } elseif ($changeReturned > 0) {
            $noteParts[] = sprintf(
                '[Cash tendered: ₱%s · Change returned: ₱%s]',
                number_format($amountReceived, 2),
                number_format($changeReturned, 2)
            );
        }
        $finalNotes = $noteParts ? implode(' — ', $noteParts) : 'Paid in full in cash.';

        // Update or create payment record
        if ($booking->payment) {
            $booking->payment->update([
                'amount' => $recordedAmount,
                'lesson_price' => $booking->effectiveLessonPrice(),
                'service_fee_amount' => $booking->effectiveServiceFeeAmount(),
                'status' => $targetStatus,
                'paid_at' => now(),
                'receipt_verified_by' => auth()->id(),
                'receipt_verified_at' => now(),
                'notes' => $finalNotes,
            ]);
        } else {
            $booking->payment()->create([
                'amount' => $recordedAmount,
                'lesson_price' => $booking->effectiveLessonPrice(),
                'service_fee_amount' => $booking->effectiveServiceFeeAmount(),
                'payment_method' => \App\Enums\PaymentMethod::Cash,
                'status' => $targetStatus,
                'paid_at' => now(),
                'receipt_verified_by' => auth()->id(),
                'receipt_verified_at' => now(),
                'notes' => $finalNotes,
            ]);
        }

        // Update booking payment_status - only the payment side, never the lifecycle.
        $booking->update([
            'payment_status' => $targetStatus,
        ]);

        app(\App\Services\EarningsLedgerService::class)->syncBookingToLedger($booking->fresh(['payment']));

        app(\App\Services\BookingEventService::class)->recordPaymentCompleted(
            $booking,
            $booking->payment?->fresh(),
            auth()->id()
        );

        $actorId = auth()->id();
        $fresh = $booking->fresh(['student', 'instructor', 'surfSpot', 'payment']);

        try {
            if ($fresh?->payment) {
                broadcast(new \App\Events\PaymentReceived($fresh->payment))->toOthers();
            }

            if ($isUnderpaid && $fresh) {
                $underpaidNotification = new CashUnderpaymentFlaggedNotification(
                    $fresh,
                    $amountReceived,
                    $totalDue,
                    $shortfall,
                    (string) $validated['notes']
                );

                $fresh->student?->notify($underpaidNotification);

                User::query()->admins()->get()->each(function (User $admin) use ($underpaidNotification, $actorId): void {
                    if ($admin->id !== $actorId) {
                        $admin->notify($underpaidNotification);
                    }
                });
            }
        } catch (\Throwable $exception) {
            Log::warning('Failed to broadcast cash payment.', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }

        if ($isUnderpaid) {
            // Generate receipt reflecting actual amount paid and any balance due
            try {
                $this->receiptService->generateReceipt($booking);
            } catch (\Throwable $e) {
                report($e);
            }
        } else {
            $this->receiptService->sendPaymentReceipts($fresh ?? $booking);
        }

        if ($isUnderpaid) {
            return back()->with(
                'success',
                sprintf(
                    'Underpayment recorded: ₱%s received of ₱%s (₱%s unpaid balance flagged for SISA Admin review).',
                    number_format($amountReceived, 2),
                    number_format($totalDue, 2),
                    number_format($shortfall, 2)
                )
            );
        }

        if ($changeReturned > 0) {
            return back()->with(
                'success',
                sprintf(
                    'Payment marked as received in full (₱%s). Change returned to student: ₱%s.',
                    number_format($totalDue, 2),
                    number_format($changeReturned, 2)
                )
            );
        }

        return back()->with('success', 'Payment marked as received in full. Official receipt generated.');
    }

    /**
     * Handle payment success webhook/redirect
     */
    public function success(Request $request, Booking $booking): Response|RedirectResponse
    {
        $this->authorize('view', $booking);

        $payment = $booking->payment;
        $transitionedToCompleted = false;

        // When returning from Xendit/PayMongo before an async webhook lands (or
        // on localhost without a webhook tunnel), verify the gateway status directly.
        if ($payment && ! $payment->isCompleted() && ! empty($payment->transaction_id)) {
            $gatewayStatus = strtolower($this->paymentService->getPaymentStatus($payment->transaction_id));

            if (in_array($gatewayStatus, ['succeeded', 'paid', 'completed'], true)) {
                $transitionedToCompleted = DB::transaction(function () use ($booking, $payment): bool {
                    $lockedBooking = Booking::with('payment')->whereKey($booking->id)->lockForUpdate()->firstOrFail();
                    $lockedPayment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

                    if ($lockedPayment->isCompleted() && $lockedBooking->status === BookingStatus::Confirmed) {
                        return false;
                    }

                    $lockedPayment->update([
                        'status' => PaymentStatus::Completed,
                        'gateway_payment_id' => $lockedPayment->gateway_payment_id ?: $lockedPayment->transaction_id,
                        'paid_at' => $lockedPayment->paid_at ?? now(),
                    ]);

                    $lockedBooking->update([
                        'status' => BookingStatus::Confirmed,
                        'payment_status' => PaymentStatus::Completed,
                        'payment_deadline_at' => null,
                    ]);

                    $eventService = app(\App\Services\BookingEventService::class);
                    $eventService->recordPaymentCompleted($lockedBooking, $lockedPayment->fresh(), $lockedBooking->student_id);
                    $eventService->recordConfirmed($lockedBooking, $lockedBooking->student_id, 'GCash');

                    return true;
                });

                $booking->refresh()->load(['student', 'instructor', 'surfSpot', 'payment']);
            } elseif (in_array($gatewayStatus, ['failed', 'voided', 'expired'], true)) {
                $payment->update([
                    'status' => PaymentStatus::Failed,
                    'checkout_url' => null,
                ]);

                return redirect()
                    ->route('student.bookings.show', $booking)
                    ->with('error', 'Payment was not completed. You can try again.');
            }
        }

        if ($transitionedToCompleted) {
            try {
                if ($booking->payment) {
                    broadcast(new \App\Events\PaymentReceived($booking->payment->fresh()))->toOthers();
                }
                broadcast(new \App\Events\BookingConfirmed($booking))->toOthers();
            } catch (\Throwable $exception) {
                Log::warning('Failed to broadcast payment success redirect.', [
                    'booking_id' => $booking->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            $this->receiptService->sendPaymentReceipts($booking);
        } elseif ($booking->payment && $booking->payment->isCompleted()) {
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

        // Only mark an active GCash checkout attempt failed when the booking is
        // still awaiting online payment (never overwrite uploaded receipts, cash
        // holds, or completed payments via a gateway cancel redirect).
        if (
            $booking->status === BookingStatus::AwaitingPayment
            && $booking->payment
            && $booking->payment->status === PaymentStatus::Pending
        ) {
            $booking->payment->update([
                'status' => PaymentStatus::Failed,
                'checkout_url' => null,
            ]);
        }

        return redirect()
            ->route('student.bookings.show', $booking)
            ->with('error', 'Payment was cancelled. You can try again.');
    }

    /**
     * Handle PayMongo / Xendit webhook
     */
    public function webhook(Request $request): \Illuminate\Http\Response
    {
        try {
            $this->paymentService->handleWebhook($request);
            return response('Webhook processed', 200);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return response($e->getMessage() ?: 'Webhook unauthorized', $e->getStatusCode());
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

        $payment = $booking->payment;
        $hasIssuedReceipt = $booking->isPaid()
            || ($payment !== null && in_array($payment->status, [
                PaymentStatus::Completed,
                PaymentStatus::Underpaid,
                PaymentStatus::RefundRequested,
                PaymentStatus::RefundProcessing,
                PaymentStatus::Refunded,
            ], true));

        abort_unless($hasIssuedReceipt, 403, 'Official receipt is only available after payment is recorded.');

        return $this->receiptService->streamReceipt($booking);
    }

    /**
     * Process refund (SISA Admin cancellation / override refund).
     */
    public function refund(
        Request $request,
        Booking $booking,
        \App\Services\BookingCancellationService $cancellationService
    ): RedirectResponse {
        abort_if($booking->payment === null, 404, 'This booking has no payment record.');

        $this->authorize('refund', $booking->payment);

        $maxRefundable = $booking->effectiveServiceFeeAmount() > 0
            ? $booking->effectiveLessonPrice()
            : (float) $booking->total_amount;

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'amount' => 'nullable|numeric|min:0.01|max:' . $maxRefundable,
        ]);

        $overrideAmount = isset($validated['amount']) ? (float) $validated['amount'] : null;

        try {
            if ($booking->status === BookingStatus::Cancelled) {
                $refund = $cancellationService->issueAdminRefund(
                    $booking,
                    auth()->user(),
                    $overrideAmount ?? $maxRefundable,
                    $validated['reason']
                );
            } else {
                $result = $cancellationService->cancel(
                    $booking,
                    \App\Enums\CancellationReason::Admin,
                    $validated['reason'],
                    auth()->user(),
                    $overrideAmount
                );
                $refund = $result['refund'];
            }

            if ($refund && $refund->status === \App\Enums\RefundStatus::ManualRequired) {
                return back()->with('success', 'Refund recorded and queued for manual GCash completion.');
            }

            return back()->with('success', 'Refund processed successfully.');
        } catch (\Throwable $e) {
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