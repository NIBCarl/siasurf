<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Jobs\ExpireBookingAcceptanceJob;
use App\Jobs\ExpirePaymentWindowJob;
use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Safety net for FR 1.9.3. The queued job normally expires an unanswered
 * request on time; this sweep catches anything a stalled queue left behind.
 */
class ExpireStaleBookingRequests extends Command
{
    protected $signature = 'bookings:expire-stale-requests {--chunk=100}';

    protected $description = 'Release booking requests the instructor never accepted within the 2 hour window';

    public function handle(): int
    {
        $expired = 0;

        Booking::query()
            ->whereIn('status', [BookingStatus::Reserved, BookingStatus::Pending])
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('status', BookingStatus::Reserved)
                        ->whereNotNull('payment_deadline_at')
                        ->where('payment_deadline_at', '<', now());
                })->orWhere(function ($q) {
                    $q->where('status', BookingStatus::Pending)
                        ->whereNotNull('accept_deadline_at')
                        ->where('accept_deadline_at', '<', now());
                })->orWhere(function ($q) {
                    $q->where('status', BookingStatus::Pending)
                        ->whereNull('accept_deadline_at')
                        ->whereNotNull('payment_deadline_at')
                        ->where('payment_deadline_at', '<', now());
                });
            })
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($bookings) use (&$expired) {
                foreach ($bookings as $booking) {
                    ExpireBookingAcceptanceJob::dispatch($booking);
                    $expired++;
                }
            });

        Booking::query()
            ->where('status', BookingStatus::AwaitingPayment)
            ->whereNotNull('payment_deadline_at')
            ->where('payment_deadline_at', '<', now())
            ->where('payment_status', '!=', PaymentStatus::Completed->value)
            ->whereDoesntHave('payment', function ($q) {
                $q->whereIn('status', [
                    PaymentStatus::Completed->value,
                    PaymentStatus::PendingVerification->value,
                ]);
            })
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($bookings) use (&$expired) {
                foreach ($bookings as $booking) {
                    ExpirePaymentWindowJob::dispatch($booking);
                    $expired++;
                }
            });

        $this->info("Queued {$expired} stale booking request(s) for expiry.");

        return self::SUCCESS;
    }
}