<?php

namespace App\Console\Commands;

use App\Enums\InstructorStatus;
use App\Models\InstructorProfile;
use App\Services\QRCodeService;
use Illuminate\Console\Command;

/**
 * Repair instructor QR codes.
 *
 * QR generation used to run from exactly one place — the admin verify action —
 * so every instructor activated any other way (seeder, edit form, status
 * change) was left with `qr_code_path = null` and a 404 on the public QR image
 * route. This command backfills those rows.
 */
class GenerateMissingQRCodes extends Command
{
    protected $signature = 'instructors:generate-qr
                            {--dry-run : Report what would be generated without writing files}';

    protected $description = 'Generate QR codes for active instructors that have none on disk';

    public function handle(QRCodeService $qrCodeService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $profiles = InstructorProfile::query()
            ->where('status', InstructorStatus::Active->value)
            ->with('user')
            ->get();

        if ($profiles->isEmpty()) {
            $this->info('No active instructors found.');

            return self::SUCCESS;
        }

        $generated = 0;
        $skipped = 0;

        foreach ($profiles as $profile) {
            $path = $profile->qr_code_path;

            if ($path && \Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("Would generate: {$profile->user?->name} (user {$profile->user_id})");
                $generated++;

                continue;
            }

            $qrCodeService->generateInstructorQR($profile);
            $this->line("Generated: {$profile->user?->name} (user {$profile->user_id})");
            $generated++;
        }

        $verb = $dryRun ? 'would generate' : 'generated';

        $this->info("{$generated} {$verb}, {$skipped} already present.");

        return self::SUCCESS;
    }
}
