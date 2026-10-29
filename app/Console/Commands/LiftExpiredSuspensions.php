<?php

namespace App\Console\Commands;

use App\Enums\SuspensionStatus;
use App\Enums\SuspensionType;
use App\Enums\InstructorStatus;
use App\Events\InstructorStatusChanged;
use App\Models\InstructorProfile;
use App\Models\InstructorStatusLog;
use App\Models\InstructorSuspension;
use App\Notifications\InstructorReactivated;
use App\Services\SuspensionService;
use Illuminate\Console\Command;

class LiftExpiredSuspensions extends Command
{
    protected $signature = 'instructors:lift-expired-suspensions';

    protected $description = 'Reactivate instructors whose suspension period has elapsed';

    public function handle(SuspensionService $suspensionService): int
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
            $suspension = InstructorSuspension::where('instructor_id', $profile->user_id)
                ->where('status', SuspensionStatus::Active->value)
                ->latest()
                ->first();

            if ($suspension) {
                $reset = $suspension->type !== SuspensionType::Manual;
                $suspensionService->lift(
                    $suspension,
                    'Suspension period served.',
                    null,
                    SuspensionStatus::Completed,
                    $reset
                );
                $this->info("Reactivated instructor #{$profile->user_id} ({$profile->user?->name}).");
                continue;
            }

            // Legacy suspension with no suspension record. Preserve current
            // behaviour: a served suspension clears legacy strikes.
            $oldStatus = $profile->status;
            $profile->update([
                'status' => InstructorStatus::Active,
                'suspended_until' => null,
                'suspension_reason' => null,
                'suspended_at' => null,
                'strike_count' => 0,
            ]);

            InstructorStatusLog::create([
                'instructor_id' => $profile->user_id,
                'from_status' => $oldStatus?->value,
                'to_status' => InstructorStatus::Active->value,
                'reason' => 'Suspension period served (legacy record).',
            ]);

            if ($profile->user) {
                try {
                    $profile->user->notify(new InstructorReactivated);
                    broadcast(new InstructorStatusChanged(
                        $profile->user,
                        InstructorStatus::Suspended,
                        InstructorStatus::Active,
                        'Suspension period served.'
                    ))->toOthers();
                } catch (\Throwable $exception) {
                    \Log::warning('Failed to reactivate instructor.', [
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
