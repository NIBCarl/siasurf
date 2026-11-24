<?php

namespace App\Console\Commands;

use App\Enums\InstructorStatus;
use App\Enums\SuspensionStatus;
use App\Enums\SuspensionType;
use App\Models\InstructorProfile;
use App\Models\InstructorSuspension;
use App\Services\SuspensionService;
use Illuminate\Console\Command;

class LiftExpiredSuspensions extends Command
{
    protected $signature = 'instructors:lift-expired-suspensions';

    protected $description = 'Transition elapsed suspensions to awaiting SISA admin reinstatement approval';

    public function handle(SuspensionService $suspensionService): int
    {
        $expiredSuspensions = InstructorSuspension::query()
            ->with('instructor')
            ->where('status', SuspensionStatus::Active->value)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->get();

        $transitioned = 0;

        foreach ($expiredSuspensions as $suspension) {
            $suspensionService->markAwaitingReinstatement($suspension);
            $transitioned++;
            $this->info("Suspension #{$suspension->id} for instructor #{$suspension->instructor_id} is now awaiting SISA reinstatement.");
        }

        // Legacy fallback: suspended profile whose duration elapsed without an InstructorSuspension row.
        $legacyProfiles = InstructorProfile::query()
            ->with('user')
            ->where('status', InstructorStatus::Suspended)
            ->whereNotNull('suspended_until')
            ->where('suspended_until', '<=', now())
            ->whereDoesntHave('suspensions', fn ($q) => $q->enforced())
            ->get();

        foreach ($legacyProfiles as $profile) {
            $legacy = InstructorSuspension::create([
                'instructor_id' => $profile->user_id,
                'type' => SuspensionType::Legacy,
                'status' => SuspensionStatus::Active,
                'reason' => $profile->suspension_reason ?? 'Legacy suspension period served.',
                'starts_at' => $profile->suspended_at ?? $profile->suspended_until->copy()->subDays(30),
                'ends_at' => $profile->suspended_until,
            ]);

            $suspensionService->markAwaitingReinstatement($legacy);
            $transitioned++;
            $this->info("Legacy suspension for instructor #{$profile->user_id} ({$profile->user?->name}) is now awaiting SISA reinstatement.");
        }

        if ($transitioned === 0) {
            $this->info('No expired suspensions awaiting reinstatement.');
        } else {
            $this->info("Marked {$transitioned} suspension(s) as awaiting SISA reinstatement.");
        }

        return self::SUCCESS;
    }
}
