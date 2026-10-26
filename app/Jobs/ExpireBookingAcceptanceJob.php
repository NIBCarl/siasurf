<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Events\BookingDeclined;
use App\Models\Booking;
use App\Notifications\BookingDeclinedNotification;
use App\Services\AvailabilityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FR 1.9.3 - a booking request the instructor never answered inside the 2 hour
 * window is released so the student can book somebody else.
 */
class ExpireBookingAcceptanceJob implements ShouldQueue
{
    use Queueable;

    protected Booking $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    public function handle(AvailabilityService $availabilityService): void
    {
        $booking = $this->booking->fresh(['instructor', 'student', 'surfSpot', 'payment']);

        if (!$booking) {
            return;
        }

        // The instructor answered in time - nothing to do.
        if ($booking->status !== BookingStatus::Pending) {
            return;
        }

        // A late reply may still be beating us to it; respect the deadline itself.
        if (!$booking->acceptDeadlinePassed()) {
            return;
        }

        DB::transaction(function () use ($booking, $availabilityService) {
            $booking->expireAcceptance();

            // Free the slot the pending request was holding.
            $availabilityService->releaseForBooking($booking);

            // A GCash reservation that never got accepted should not stay "pending".
            if ($booking->payment && $booking->payment->status === PaymentStatus::Pending) {
                $booking->payment->update(['status' => PaymentStatus::Failed]);
            }

            broadcast(new BookingDeclined(
                $booking->fresh(['instructor', 'student', 'surfSpot']),
                $booking->decline_reason,
                true
            ))->toOthers();

            foreach ([$booking->student, $booking->instructor] as $party) {
                $party?->notify(new BookingDeclinedNotification($booking, true));
            }

            Log::info("Booking #{$booking->id} expired - instructor did not respond within " . Booking::ACCEPT_WINDOW_HOURS . ' hours.');
        });
    }
}