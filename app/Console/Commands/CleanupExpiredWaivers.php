<?php

namespace App\Console\Commands;

use App\Models\Waiver;
use App\Services\WaiverService;
use Illuminate\Console\Command;

/**
 * BR-4.6 — legal documents are retained for seven years, then destroyed.
 *
 * WaiverService::cleanupExpiredWaivers() already implemented the rule, but
 * nothing ever called it, so expired PDFs would have accumulated forever. This
 * command makes the retention policy actually run.
 */
class CleanupExpiredWaivers extends Command
{
    protected $signature = 'waivers:cleanup-expired {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete waiver PDFs and records past their 7-year retention window';

    public function handle(WaiverService $waiverService): int
    {
        if ($this->option('dry-run')) {
            $count = Waiver::where('retention_until', '<', now())->count();
            $this->info("{$count} waiver(s) are past retention and would be deleted.");

            return self::SUCCESS;
        }

        $deleted = $waiverService->cleanupExpiredWaivers();

        $this->info("Deleted {$deleted} expired waiver(s).");

        return self::SUCCESS;
    }
}
