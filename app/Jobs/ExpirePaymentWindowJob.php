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

        DB::transaction(function () use ($booking, $availabilityService) {
            $locked = Booking::with(['instructor', 'student', 'surfSpot', 'payment'])
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== BookingStatus::AwaitingPayment) {
                return;
            }

            if ($locked->payment?->isCompleted() || $locked->payment?->isAwaitingVerification()) {
                return;
            }

            if ($locked->payment_deadline_at && $locked->payment_deadline_at->isFuture()) {
                return;
            }

            // FR 1.4 - its own terminal state, so a payment timeout is not
            // confused with a deliberate cancellation in reports.
            $locked->markPaymentTimedOut();

            // Update payment record to Failed
            if ($locked->payment && $locked->payment->status === PaymentStatus::Pending) {
                $locked->payment->update(['status' => PaymentStatus::Failed]);
            }

            // FR 1.8.3 - hand the slot back so the instructor can be booked again.
            $availabilityService->releaseForBooking($locked);

            $locked->refresh();

            DB::afterCommit(function () use ($locked) {
                broadcast(new BookingCancelled($locked, 'Payment window expired.'))->toOthers();

                $locked->student?->notify(new BookingCancelledNotification($locked, 'Payment window expired.'));
                $locked->instructor?->notify(new BookingCancelledNotification($locked, 'Payment window expired.'));
            });

            Log::info("Booking #{$locked->id} payment window expired; slot released.");
        });
    }
}
