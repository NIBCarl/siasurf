<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Jobs\ExpireBookingAcceptanceJob;
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
            ->where('status', BookingStatus::Pending)
            ->whereNotNull('accept_deadline_at')
            ->where('accept_deadline_at', '<', now())
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($bookings) use (&$expired) {
                foreach ($bookings as $booking) {
                    ExpireBookingAcceptanceJob::dispatch($booking);
                    $expired++;
                }
            });

        $this->info("Queued {$expired} stale booking request(s) for expiry.");

        return self::SUCCESS;
    }
}