<?php

namespace App\Console\Commands;

use App\Models\InstructorDailyLedger;
use App\Services\EarningsLedgerService;
use Illuminate\Console\Command;

class CheckOverdueCashRemittances extends Command
{
    protected $signature = 'ledger:check-remittances
                            {--dry-run : Report overdue daily ledger entries without updating or notifying}';

    protected $description = 'Flag unsettled cash service fee remittances past their remittance due date as overdue and notify instructors';

    public function handle(EarningsLedgerService $ledgerService): int
    {
        if ($this->option('dry-run')) {
            $count = InstructorDailyLedger::query()->overdueRemittance()->count();
            $this->info("Dry run: {$count} daily ledger entry/entries past cash remittance due date.");

            return self::SUCCESS;
        }

        $result = $ledgerService->markOverdueRemittances(sendNotifications: true);

        $this->info(sprintf(
            '%d overdue ledger row(s) found; %d newly marked overdue; %d instructor(s) notified.',
            $result['overdue_rows'],
            $result['updated_rows'],
            $result['notified_instructors']
        ));

        return self::SUCCESS;
    }
}
