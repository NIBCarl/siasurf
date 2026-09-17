<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\Availability;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Events\BookingCancelled;
use App\Notifications\BookingCancelledNotification;
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
    public function handle(): void
    {
        // Reload booking to get the latest status
        $booking = $this->booking->fresh(['instructor', 'student', 'surfSpot', 'payment']);

        if (!$booking) {
            return;
        }

        // Only expire if it is still awaiting payment
        if ($booking->status !== BookingStatus::AwaitingPayment) {
            return;
        }

        DB::transaction(function () use ($booking) {
            // Cancel booking using model method
            $booking->cancel('Payment window expired.');

            // Update payment record to Failed
            if ($booking->payment) {
                $booking->payment->update([
                    'status' => PaymentStatus::Failed,
                ]);
            }

            // Release instructor's availability block
            Availability::where([
                'user_id' => $booking->instructor_id,
                'specific_date' => $booking->date instanceof \DateTimeInterface ? $booking->date->format('Y-m-d') : $booking->date,
                'time_period' => $booking->time_period instanceof \BackedEnum ? $booking->time_period->value : $booking->time_period,
                'is_available' => false,
            ])->delete();

            // Broadcast WebSocket cancellation event
            broadcast(new BookingCancelled($booking, 'Payment window expired.'))->toOthers();

            // Notify both student and instructor via database/email notifications
            if ($booking->student) {
                $booking->student->notify(new BookingCancelledNotification($booking, 'Payment window expired.'));
            }
            if ($booking->instructor) {
                $booking->instructor->notify(new BookingCancelledNotification($booking, 'Payment window expired.'));
            }

            Log::info("Booking #{$booking->id} has been expired due to payment window timeout.");
        });
    }
}
