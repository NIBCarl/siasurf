<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\PaymentStatus;
use App\Events\BookingCancelled;
use App\Notifications\BookingCancelledNotification;
use App\Services\AvailabilityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpirePaymentWindowJob implements ShouldQueue
{
    use Queueable;

    protected Booking $booking;

    /**
     * Create a new job instance.
     */
    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    /**
     * Execute the job.
     */
    public function handle(AvailabilityService $availabilityService): void
    {
        // Reload booking to get the latest status
        $booking = $this->booking->fresh(['instructor', 'student', 'surfSpot', 'payment']);

        if (!$booking) {
            return;
        }

        // Only expire if the student never completed payment
        if ($booking->status !== BookingStatus::AwaitingPayment) {
            return;
        }

        // A GCash receipt has already been submitted. The money may be sitting
        // in the instructor's account, so the hold must not auto-release while
        // a human is reviewing the proof of payment.
        if ($booking->payment?->hasReceipt()) {
            return;
        }

        // A late payment may still be landing; respect the deadline itself.
        if ($booking->payment_deadline_at && $booking->payment_deadline_at->isFuture()) {
            return;
        }

        DB::transaction(function () use ($booking, $availabilityService) {
            // FR 1.4 - its own terminal state, so a payment timeout is not
            // confused with a deliberate cancellation in reports.
            $booking->markPaymentTimedOut();

            // Update payment record to Failed
            if ($booking->payment && $booking->payment->status === PaymentStatus::Pending) {
                $booking->payment->update(['status' => PaymentStatus::Failed]);
            }

            // FR 1.8.3 - hand the slot back so the instructor can be booked again.
            $availabilityService->releaseForBooking($booking);

            $booking->refresh();

            // Broadcast WebSocket cancellation event
            broadcast(new BookingCancelled($booking, 'Payment window expired.'))->toOthers();

            // Notify both student and instructor via database/email notifications
            $booking->student?->notify(new BookingCancelledNotification($booking, 'Payment window expired.'));
            $booking->instructor?->notify(new BookingCancelledNotification($booking, 'Payment window expired.'));

            Log::info("Booking #{$booking->id} payment window expired; slot released.");
        });
    }
}
