<?php

namespace App\Services;

use App\Contracts\PaymentServiceInterface;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Events\BookingAwaitingPayment;
use App\Events\BookingConfirmed;
use App\Exceptions\BookingRequestException;
use App\Jobs\ExpirePaymentWindowJob;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for accepting a booking request.
 *
 * The instructor's manual accept and Instant Book (where a trusted instructor's
 * calendar confirms immediately) both run through here, so the GCash/cash
 * branching, slot locking, payment-intent creation and broadcasts cannot drift
 * apart. Previously this logic existed in three separate places.
 */
class BookingAcceptanceService
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected PaymentServiceInterface $paymentService
    ) {
    }

    /**
     * Accept the request: lock the slot and either wait for payment or confirm.
     *
     * @throws BookingRequestException
     */
    public function accept(Booking $booking): Booking
    {
        $this->assertAcceptable($booking);

        return DB::transaction(function () use ($booking) {
            $booking->update([
                'accepted_at' => $booking->accepted_at ?? now(),
            ]);

            // FR 1.8.3 - the lesson now owns this slot.
            $this->availabilityService->blockForBooking($booking);

            $booking->refresh();

            return $this->paymentMethodFor($booking) === PaymentMethod::GCash
                ? $this->awaitPayment($booking)
                : $this->confirmWithCash($booking);
        });
    }

    /**
     * A request can only be accepted while it is still awaiting an answer and
     * before the lesson is too close to run.
     */
    private function assertAcceptable(Booking $booking): void
    {
        if ($booking->status !== BookingStatus::Pending) {
            throw new BookingRequestException('This booking request has already been handled.');
        }

        if ($booking->acceptDeadlinePassed()) {
            throw new BookingRequestException(
                'The response window has closed, so this request was released and the slot is open again.'
            );
        }

        if ($booking->isTooCloseToStartToAccept()) {
            throw new BookingRequestException(
                'This lesson starts too soon to accept it now. Ask the student to reschedule.'
            );
        }
    }

    /**
     * Fall back to cash when no payment record exists. That is the conservative
     * direction: no money is taken, and the instructor confirms at the beach.
     */
    private function paymentMethodFor(Booking $booking): PaymentMethod
    {
        $payment = $booking->payment;

        if (!$payment) {
            Log::warning('Booking accepted without a payment record; defaulting to cash.', [
                'booking_id' => $booking->id,
            ]);

            return PaymentMethod::Cash;
        }

        return $payment->payment_method;
    }

    /**
     * GCash: hold the slot and give the student the 15-minute payment window.
     */
    private function awaitPayment(Booking $booking): Booking
    {
        $deadline = $booking->paymentDeadlineFrom();

        $booking->update([
            'status' => BookingStatus::AwaitingPayment,
            'payment_deadline_at' => $deadline,
        ]);

        $payment = $booking->payment;

        if ($payment) {
            $intent = $this->paymentService->createPaymentIntent($booking);

            $payment->update([
                'transaction_id' => $intent['transaction_id'],
                'checkout_url' => $intent['checkout_url'],
            ]);
        }

        $booking->refresh()->load(['instructor', 'student', 'surfSpot', 'payment']);

        broadcast(new BookingAwaitingPayment($booking))->toOthers();

        // The lock releases on its own, clamped to the lesson start.
        ExpirePaymentWindowJob::dispatch($booking)
            ->delay($deadline);

        return $booking;
    }

    /**
     * Cash: the lesson is confirmed straight away and paid at the beach.
     */
    private function confirmWithCash(Booking $booking): Booking
    {
        $booking->update(['status' => BookingStatus::Confirmed]);

        $booking->refresh()->load(['instructor', 'student', 'surfSpot']);

        broadcast(new BookingConfirmed($booking))->toOthers();

        return $booking;
    }
}