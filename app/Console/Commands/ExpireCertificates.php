<?php

namespace App\Console\Commands;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Certificate / SISA ID expiry sweep.
 *
 * Visibility is decided at read time (`User::hasVerifiedCertificate()` and the
 * search filter), so an expired certificate hides an instructor immediately
 * without waiting for this command. The sweep exists for the things that
 * cannot be derived: flipping `status` to Expired for reporting, and telling
 * the instructor and SISA before/after the ID lapses.
 */
class ExpireCertificates extends Command
{
    protected $signature = 'certs:expire
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Mark newly expired certificates as Expired and warn about upcoming expiry';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $newlyExpired = Certificate::query()
            ->where('status', CertificateStatus::Verified->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($newlyExpired as $certificate) {
            if ($dryRun) {
                $this->line("Would expire: #{$certificate->id} ({$certificate->type->label()}) for user {$certificate->user_id}");

                continue;
            }

            $certificate->update(['status' => CertificateStatus::Expired->value]);

            Log::info('Certificate expired', [
                'certificate_id' => $certificate->id,
                'user_id' => $certificate->user_id,
                'type' => $certificate->type->value,
                'expired_at' => $certificate->expires_at?->toIso8601String(),
            ]);

            $this->line("Expired: #{$certificate->id} ({$certificate->type->label()}) for user {$certificate->user_id}");
        }

        $expiringSoon = Certificate::query()
            ->where('status', CertificateStatus::Verified->value)
            ->expiringSoon(30)
            ->get();

        foreach ($expiringSoon as $certificate) {
            $days = (int) now()->diffInDays($certificate->expires_at, false);

            Log::warning('Certificate expiring soon', [
                'certificate_id' => $certificate->id,
                'user_id' => $certificate->user_id,
                'days_left' => $days,
            ]);
        }

        $verb = $dryRun ? 'would expire' : 'expired';

        $this->info(sprintf(
            '%d certificate(s) %s; %d expiring within 30 days.',
            $newlyExpired->count(),
            $verb,
            $expiringSoon->count()
        ));

        return self::SUCCESS;
    }
}
