<?php

namespace App\Console\Commands;

use App\Enums\CertificateStatus;
use App\Enums\InstructorStatus;
use App\Models\Certificate;
use App\Models\User;
use App\Notifications\CertificateExpiryNotification;
use App\Notifications\SisaIdExpiryNotification;
use App\Services\SisaIdCardService;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
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

    protected $description = 'Mark newly expired certificates as Expired and notify about expiring or expired certificates and SISA IDs';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $admins = $dryRun ? collect() : User::query()->admins()->get();

        $newlyExpired = Certificate::query()
            ->with('user')
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

            $expiredNotification = new CertificateExpiryNotification($certificate, expired: true);

            try {
                $certificate->user?->notify($expiredNotification);
            } catch (\Throwable $exception) {
                Log::warning('Failed to notify instructor of expired certificate.', [
                    'certificate_id' => $certificate->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            foreach ($admins as $admin) {
                try {
                    $admin->notify($expiredNotification);
                } catch (\Throwable $exception) {
                    Log::warning('Failed to notify admin of expired certificate.', [
                        'certificate_id' => $certificate->id,
                        'admin_id' => $admin->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $this->line("Expired: #{$certificate->id} ({$certificate->type->label()}) for user {$certificate->user_id}");
        }

        $expiringSoon = Certificate::query()
            ->with('user')
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

            if ($dryRun) {
                continue;
            }

            $expiresDate = $certificate->expires_at?->toDateString();
            $alreadyNotified = DatabaseNotification::query()
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $certificate->user_id)
                ->where('data->type', 'certificate_expiring_soon')
                ->where('data->certificate_id', $certificate->id)
                ->when(
                    $expiresDate !== null,
                    fn ($query) => $query->where('data->expires_at', $expiresDate)
                )
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            $expiringNotification = new CertificateExpiryNotification(
                $certificate,
                expired: false,
                daysLeft: max(0, $days)
            );

            try {
                $certificate->user?->notify($expiringNotification);
            } catch (\Throwable $exception) {
                Log::warning('Failed to notify instructor of expiring certificate.', [
                    'certificate_id' => $certificate->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            foreach ($admins as $admin) {
                try {
                    $admin->notify($expiringNotification);
                } catch (\Throwable $exception) {
                    Log::warning('Failed to notify admin of expiring certificate.', [
                        'certificate_id' => $certificate->id,
                        'admin_id' => $admin->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $idCardService = app(SisaIdCardService::class);
        $expiredIdsCount = 0;
        $expiringSoonIdsCount = 0;

        $verifiedInstructors = User::query()
            ->instructors()
            ->whereHas('instructorProfile', fn ($q) => $q->whereIn('status', [
                InstructorStatus::Active->value,
                InstructorStatus::Inactive->value,
            ]))
            ->whereHas('certificates')
            ->with(['instructorProfile', 'certificates'])
            ->get();

        foreach ($verifiedInstructors as $instructor) {
            $validity = $idCardService->resolveValidity($instructor);
            $expiresAt = $validity['expires_at'];
            $sisaIdCode = $validity['sisa_id_code'];

            $isExpired = $expiresAt->lt(now());
            $isExpiringSoon = ! $isExpired && $expiresAt->lte(now()->addDays(30));

            if (! $isExpired && ! $isExpiringSoon) {
                continue;
            }

            if ($isExpired) {
                $expiredIdsCount++;
                $notificationType = 'sisa_id_expired';
                $daysLeft = null;
                Log::info('SISA ID expired', [
                    'instructor_id' => $instructor->id,
                    'sisa_id_code' => $sisaIdCode,
                    'expired_at' => $expiresAt->toIso8601String(),
                ]);
            } else {
                $expiringSoonIdsCount++;
                $notificationType = 'sisa_id_expiring_soon';
                $daysLeft = max(0, (int) now()->diffInDays($expiresAt, false));
                Log::warning('SISA ID expiring soon', [
                    'instructor_id' => $instructor->id,
                    'sisa_id_code' => $sisaIdCode,
                    'days_left' => $daysLeft,
                ]);
            }

            if ($dryRun) {
                continue;
            }

            $alreadyNotified = DatabaseNotification::query()
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $instructor->id)
                ->where('data->type', $notificationType)
                ->where('data->expires_at', $expiresAt->toDateString())
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            $idNotification = new SisaIdExpiryNotification(
                $instructor,
                $sisaIdCode,
                $expiresAt,
                expired: $isExpired,
                daysLeft: $daysLeft
            );

            try {
                $instructor->notify($idNotification);
            } catch (\Throwable $exception) {
                Log::warning('Failed to notify instructor of SISA ID expiry status.', [
                    'instructor_id' => $instructor->id,
                    'sisa_id_code' => $sisaIdCode,
                    'error' => $exception->getMessage(),
                ]);
            }

            foreach ($admins as $admin) {
                try {
                    $admin->notify($idNotification);
                } catch (\Throwable $exception) {
                    Log::warning('Failed to notify admin of SISA ID expiry status.', [
                        'instructor_id' => $instructor->id,
                        'admin_id' => $admin->id,
                        'sisa_id_code' => $sisaIdCode,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $verb = $dryRun ? 'would expire' : 'expired';

        $this->info(sprintf(
            '%d certificate(s) %s; %d certificate(s) expiring within 30 days; %d SISA ID(s) %s; %d SISA ID(s) expiring within 30 days.',
            $newlyExpired->count(),
            $verb,
            $expiringSoon->count(),
            $expiredIdsCount,
            $verb,
            $expiringSoonIdsCount
        ));

        return self::SUCCESS;
    }
}
