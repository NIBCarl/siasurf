<?php

namespace App\Services;

use App\Enums\AppealStatus;
use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\InstructorStatus;
use App\Enums\StrikeStatus;
use App\Enums\SuspensionStatus;
use App\Enums\SuspensionType;
use App\Events\InstructorStatusChanged;
use App\Models\Booking;
use App\Models\InstructorProfile;
use App\Models\InstructorStatusLog;
use App\Models\InstructorSuspension;
use App\Models\SafetyIncident;
use App\Models\User;
use App\Notifications\InstructorReactivated;
use App\Notifications\InstructorSuspended;
use App\Notifications\SuspensionReadyForReinstatementNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SuspensionService
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected BookingCancellationService $cancellationService,
    ) {}

    public function suspendManually(User $instructor, string $reason, int $days, User $admin): InstructorSuspension
    {
        return $this->suspend($instructor, SuspensionType::Manual, $reason, $days, $admin, null, null);
    }

    public function suspendAutomatically(
        User $instructor,
        SafetyIncident $incident,
        string $reason,
        int $days = 30,
        int $strikeTier = 3
    ): InstructorSuspension {
        return $this->suspend($instructor, SuspensionType::Automatic, $reason, $days, null, $incident, $strikeTier);
    }

    private function suspend(
        User $instructor,
        SuspensionType $type,
        string $reason,
        int $days,
        ?User $admin,
        ?SafetyIncident $incident,
        ?int $strikeTier
    ): InstructorSuspension {
        $oldStatus = null;
        $duration = max(1, $days);
        $isTier3 = $type === SuspensionType::Automatic && ($strikeTier ?? 3) >= 3;

        $suspension = DB::transaction(function () use (
            $instructor,
            $type,
            $reason,
            $duration,
            $admin,
            $incident,
            $strikeTier,
            $isTier3,
            &$oldStatus
        ) {
            $profile = InstructorProfile::where('user_id', $instructor->id)
                ->lockForUpdate()
                ->firstOrFail();

            $enforced = InstructorSuspension::where('instructor_id', $instructor->id)
                ->enforced()
                ->lockForUpdate()
                ->first();

            if ($enforced) {
                return $enforced;
            }

            $oldStatus = $profile->status;
            $startsAt = now();
            $endsAt = $startsAt->copy()->addDays($duration);

            $suspension = InstructorSuspension::create([
                'instructor_id' => $instructor->id,
                'type' => $type,
                'strike_tier' => $type === SuspensionType::Automatic ? ($strikeTier ?? 3) : null,
                'status' => SuspensionStatus::Active,
                'reason' => $reason,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'requires_sisa_id_confiscation' => $isTier3,
                'suspended_by' => $admin?->id,
                'triggering_incident_id' => $incident?->id,
            ]);

            $profile->update([
                'status' => InstructorStatus::Suspended,
                'suspended_until' => $endsAt,
                'suspension_reason' => $reason,
                'suspended_at' => $startsAt,
            ]);

            InstructorStatusLog::create([
                'instructor_id' => $instructor->id,
                'from_status' => $oldStatus?->value,
                'to_status' => InstructorStatus::Suspended->value,
                'reason' => $reason,
                'changed_by' => $admin?->id,
            ]);

            return $suspension->fresh();
        });

        $this->cancelUpcomingBookings($instructor, $reason);

        try {
            $instructor->notify(new InstructorSuspended(
                $reason,
                $duration,
                $type === SuspensionType::Automatic ? ($strikeTier ?? 3) : null,
                $isTier3
            ));
        } catch (\Throwable $exception) {
            Log::warning('Failed to send suspension notification.', [
                'instructor_id' => $instructor->id,
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            broadcast(new InstructorStatusChanged(
                $instructor->fresh(),
                $oldStatus ?? InstructorStatus::Active,
                InstructorStatus::Suspended,
                $reason
            ))->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('Failed to broadcast suspension.', [
                'instructor_id' => $instructor->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $suspension;
    }

    /**
     * Upgrade an enforced Tier 2 (1-week) suspension to Tier 3 (1-month + SISA ID confiscation).
     */
    public function upgradeToTier3(
        InstructorSuspension $suspension,
        SafetyIncident $incident,
        string $reason,
        int $days = 30
    ): InstructorSuspension {
        $duration = max(1, $days);
        $oldStatus = null;

        $upgraded = DB::transaction(function () use ($suspension, $incident, $reason, $duration, &$oldStatus) {
            $locked = InstructorSuspension::whereKey($suspension->id)->lockForUpdate()->firstOrFail();
            $profile = InstructorProfile::where('user_id', $locked->instructor_id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldStatus = $profile->status;
            $endsAt = now()->addDays($duration);

            $locked->update([
                'status' => SuspensionStatus::Active,
                'strike_tier' => 3,
                'requires_sisa_id_confiscation' => true,
                'reason' => $reason,
                'triggering_incident_id' => $incident->id,
                'ends_at' => $endsAt,
                'reinstatement_eligible_at' => null,
            ]);

            $profile->update([
                'status' => InstructorStatus::Suspended,
                'suspended_until' => $endsAt,
                'suspension_reason' => $reason,
            ]);

            InstructorStatusLog::create([
                'instructor_id' => $locked->instructor_id,
                'from_status' => $oldStatus?->value,
                'to_status' => InstructorStatus::Suspended->value,
                'reason' => 'Escalated to 3-strike (1-month) suspension: '.$reason,
                'changed_by' => null,
            ]);

            return $locked->fresh();
        });

        $instructor = $upgraded->instructor;

        if ($instructor) {
            $this->cancelUpcomingBookings($instructor, $reason);

            try {
                $instructor->notify(new InstructorSuspended($reason, $duration, 3, true));
            } catch (\Throwable $exception) {
                Log::warning('Failed to send Tier 3 upgrade notification.', [
                    'instructor_id' => $instructor->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            try {
                broadcast(new InstructorStatusChanged(
                    $instructor->fresh(),
                    $oldStatus ?? InstructorStatus::Suspended,
                    InstructorStatus::Suspended,
                    $reason
                ))->toOthers();
            } catch (\Throwable $exception) {
                Log::warning('Failed to broadcast Tier 3 suspension upgrade.', [
                    'instructor_id' => $instructor->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $upgraded;
    }

    /**
     * Downgrade an enforced Tier 3 suspension back to Tier 2 (1-week) when strikes drop to 2.
     */
    public function downgradeToTier2(
        InstructorSuspension $suspension,
        ?User $admin,
        string $reason
    ): InstructorSuspension {
        $tier2Days = (int) config('siasurf.strikes.tier2_suspension_days', 7);

        return DB::transaction(function () use ($suspension, $admin, $reason, $tier2Days) {
            $locked = InstructorSuspension::whereKey($suspension->id)->lockForUpdate()->firstOrFail();
            $profile = InstructorProfile::where('user_id', $locked->instructor_id)
                ->lockForUpdate()
                ->first();

            $baseStart = $locked->starts_at ?? now();
            $newEndsAt = $baseStart->copy()->addDays(max(1, $tier2Days));
            $elapsed = $newEndsAt->lte(now());

            $locked->update([
                'strike_tier' => 2,
                'requires_sisa_id_confiscation' => false,
                'ends_at' => $newEndsAt,
                'status' => $elapsed ? SuspensionStatus::AwaitingReinstatement : SuspensionStatus::Active,
                'reinstatement_eligible_at' => $elapsed ? now() : null,
                'reason' => $reason,
            ]);

            if ($profile) {
                $profile->update([
                    'status' => InstructorStatus::Suspended,
                    'suspended_until' => $newEndsAt,
                    'suspension_reason' => $reason,
                ]);
            }

            InstructorStatusLog::create([
                'instructor_id' => $locked->instructor_id,
                'from_status' => InstructorStatus::Suspended->value,
                'to_status' => InstructorStatus::Suspended->value,
                'reason' => 'Downgraded to 2-strike (1-week) suspension: '.$reason,
                'changed_by' => $admin?->id,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Transition an expired Active suspension into AwaitingReinstatement without making
     * the instructor bookable. SISA admin must still explicitly approve reinstatement.
     */
    public function markAwaitingReinstatement(InstructorSuspension $suspension): InstructorSuspension
    {
        $updated = DB::transaction(function () use ($suspension) {
            $locked = InstructorSuspension::whereKey($suspension->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SuspensionStatus::Active) {
                return $locked;
            }

            $locked->update([
                'status' => SuspensionStatus::AwaitingReinstatement,
                'reinstatement_eligible_at' => now(),
            ]);

            return $locked->fresh();
        });

        if ($updated->status === SuspensionStatus::AwaitingReinstatement) {
            User::where('role', 'admin')->get()->each(function (User $admin) use ($updated) {
                try {
                    $admin->notify(new SuspensionReadyForReinstatementNotification($updated));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to notify admin about suspension awaiting reinstatement.', [
                        'suspension_id' => $updated->id,
                        'admin_id' => $admin->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
        }

        return $updated;
    }

    public function lift(
        InstructorSuspension $suspension,
        string $reason,
        ?User $admin = null,
        SuspensionStatus $status = SuspensionStatus::Completed,
        ?bool $resetStrikes = null
    ): InstructorSuspension {
        $oldStatus = null;

        $suspension = DB::transaction(function () use ($suspension, $reason, $admin, $status, $resetStrikes, &$oldStatus) {
            $locked = InstructorSuspension::whereKey($suspension->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isEnforced()) {
                return $locked;
            }

            $profile = InstructorProfile::where('user_id', $locked->instructor_id)
                ->lockForUpdate()
                ->first();

            if (! $profile) {
                return $locked;
            }

            $oldStatus = $profile->status;
            $shouldReset = $resetStrikes ?? $locked->shouldResetStrikesOnLift();

            $locked->update([
                'status' => $status,
                'ends_at' => $status === SuspensionStatus::Completed
                    ? ($locked->ends_at ?? now())
                    : now(),
                'reinstated_at' => now(),
                'reinstated_by' => $admin?->id,
                'reinstatement_note' => $reason,
            ]);

            $profile->update([
                'status' => InstructorStatus::Active,
                'suspended_until' => null,
                'suspension_reason' => null,
                'suspended_at' => null,
            ]);

            if ($shouldReset) {
                $profile->user->instructorStrikes()
                    ->where('status', StrikeStatus::Active->value)
                    ->update([
                        'status' => StrikeStatus::Served,
                        'updated_at' => now(),
                    ]);

                $profile->update(['strike_count' => 0]);
            }

            if (in_array($status, [SuspensionStatus::Completed, SuspensionStatus::RevokedByAdmin], true)) {
                $locked->appeals()
                    ->where('status', AppealStatus::Pending->value)
                    ->update([
                        'status' => AppealStatus::Expired,
                        'reviewed_at' => now(),
                        'admin_response' => $status === SuspensionStatus::RevokedByAdmin
                            ? 'The suspension was revoked before the appeal was decided.'
                            : 'The suspension period ended before the appeal was decided.',
                    ]);
            }

            InstructorStatusLog::create([
                'instructor_id' => $locked->instructor_id,
                'from_status' => $oldStatus?->value,
                'to_status' => InstructorStatus::Active->value,
                'reason' => $reason,
                'changed_by' => $admin?->id,
            ]);

            return $locked->fresh();
        });

        $instructor = $suspension->instructor;

        if ($oldStatus === InstructorStatus::Suspended && $instructor) {
            try {
                $instructor->notify(new InstructorReactivated);
            } catch (\Throwable $exception) {
                Log::warning('Failed to send reactivation notification.', [
                    'instructor_id' => $instructor->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            try {
                broadcast(new InstructorStatusChanged(
                    $instructor->fresh(),
                    InstructorStatus::Suspended,
                    InstructorStatus::Active,
                    $reason
                ))->toOthers();
            } catch (\Throwable $exception) {
                Log::warning('Failed to broadcast reactivation.', [
                    'instructor_id' => $instructor->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $suspension;
    }

    protected function cancelUpcomingBookings(User $instructor, string $reason): void
    {
        $bookings = Booking::where('instructor_id', $instructor->id)
            ->whereIn('status', [
                BookingStatus::Reserved->value,
                BookingStatus::Pending->value,
                BookingStatus::AwaitingPayment->value,
                BookingStatus::Confirmed->value,
            ])
            ->whereDate('date', '>=', today())
            ->get();

        foreach ($bookings as $booking) {
            try {
                $this->cancellationService->cancel(
                    $booking,
                    CancellationReason::InstructorSuspended,
                    'Instructor account suspended: '.$reason,
                    null,
                    null,
                    'The instructor account was suspended. SISA will assist with rebooking or a full lesson refund.'
                );
            } catch (\Throwable $exception) {
                Log::warning('Failed to cancel a booking during suspension.', [
                    'booking_id' => $booking->id,
                    'instructor_id' => $instructor->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
