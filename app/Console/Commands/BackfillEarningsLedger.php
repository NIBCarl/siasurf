<?php

namespace App\Console\Commands;

use App\Services\EarningsLedgerService;
use Illuminate\Console\Command;

class BackfillEarningsLedger extends Command
{
    protected $signature = 'ledger:backfill
                            {--instructor= : Only backfill bookings for a specific instructor user ID}';

    protected $description = 'Backfill instructor daily earnings ledger entries from existing settled and retained bookings';

    public function handle(EarningsLedgerService $ledgerService): int
    {
        $instructorOption = $this->option('instructor');
        $instructorId = $instructorOption !== null && $instructorOption !== ''
            ? (int) $instructorOption
            : null;

        $count = $ledgerService->backfillExistingBookings($instructorId);

        $this->info("Synced {$count} booking(s) into the daily earnings ledger.");

        return self::SUCCESS;
    }
}
