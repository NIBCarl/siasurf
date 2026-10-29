<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Enabling email verification would otherwise lock out every account created
 * before the feature shipped, because those rows have a NULL
 * `email_verified_at`. This command stamps them as verified once.
 *
 * New sign-ups are NOT affected: they still have to click the emailed link.
 *
 *   php artisan users:grandfather-verification --dry-run
 *   php artisan users:grandfather-verification --force
 */
class GrandfatherVerifiedUsers extends Command
{
    protected $signature = 'users:grandfather-verification
                            {--dry-run : Report how many accounts would be marked verified}
                            {--force : Run without the confirmation prompt}';

    protected $description = 'Mark pre-existing accounts (NULL email_verified_at) as email-verified';

    public function handle(): int
    {
        $pending = User::query()->whereNull('email_verified_at');

        if ($this->option('dry-run')) {
            $this->info($pending->count().' account(s) would be marked as verified.');

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm('Mark every currently-unverified account as verified? Accounts created after this run are unaffected.')) {
            $this->warn('Aborted. Nothing was changed.');

            return self::FAILURE;
        }

        $updated = $pending->update(['email_verified_at' => now()]);

        $this->info("Marked {$updated} account(s) as verified.");

        return self::SUCCESS;
    }
}
