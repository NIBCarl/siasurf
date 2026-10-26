<?php

namespace App\Console\Commands;

use App\Enums\InstructorStatus;
use App\Events\InstructorStatusChanged;
use App\Models\InstructorProfile;
use App\Notifications\InstructorReactivated;
use Illuminate\Console\Command;

/**
 * FR 3.x — a suspension is a timed penalty, so it must lift itself.
 *
 * Before this command existed, `suspended_until` was written on auto-suspend and
 * then nothing ever read it back: an instructor hit by the 3-strike rule (or by
 * a manual suspension) stayed invisible to search forever, even after the month
 * had passed. Reactivation is now a scheduled sweep rather than a manual admin
 * chore.
 */
class LiftExpiredSuspensions extends Command
{
    protected $signature = 'instructors:lift-expired-suspensions';

    protected $description = 'Reactivate instructors whose suspension period has elapsed';

    public function handle(): int
    {
        $expired = InstructorProfile::query()
            ->with('user')
            ->where('status', InstructorStatus::Suspended)
            ->whereNotNull('suspended_until')
            ->where('suspended_until', '<=', now())
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No suspensions to lift.');

            return self::SUCCESS;
        }

        foreach ($expired as $profile) {
            $oldStatus = $profile->status;

            $profile->update([
                'status' => InstructorStatus::Active,
                'suspended_until' => null,
                // A served suspension wipes the slate clean; otherwise the very
                // next incident would immediately re-trigger the 3-strike rule.
                'strike_count' => 0,
            ]);

            if ($profile->user) {
                $profile->user->notify(new InstructorReactivated);

                try {
                    broadcast(new InstructorStatusChanged(
                        $profile->user,
                        $oldStatus,
                        InstructorStatus::Active,
                        'Suspension period served.'
                    ))->toOthers();
                } catch (\Throwable $exception) {
                    \Log::warning('Failed to broadcast reactivation.', [
                        'instructor_id' => $profile->user_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $this->info("Reactivated instructor #{$profile->user_id} ({$profile->user?->name}).");
        }

        $this->info("Lifted {$expired->count()} suspension(s).");

        return self::SUCCESS;
    }
}
