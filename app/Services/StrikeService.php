<?php

namespace App\Services;

use App\Enums\FineStatus;
use App\Enums\IncidentOutcome;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\StrikeStatus;
use App\Enums\SuspensionStatus;
use App\Enums\SuspensionType;
use App\Models\InstructorFine;
use App\Models\InstructorProfile;
use App\Models\InstructorStrike;
use App\Models\InstructorSuspension;
use App\Models\SafetyIncident;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for strike application, fine recording, and revocation.
 *
 * V3 3-Strike Ladder:
 *   1 active strike  -> Official warning + ₱500 fine recorded as due (collected outside system)
 *   2 active strikes -> 1-week (7-day) suspension (requires SISA approval for reinstatement)
 *   3+ active strikes -> 1-month (30-day) suspension + SISA ID confiscated outside system
 *
 * Critical incidents are handled outside the system (0 in-system strikes).
 */
class StrikeService
{
    public const TIER1_THRESHOLD = 1;

    public const TIER2_THRESHOLD = 2;

    public const TIER3_THRESHOLD = 3;

    public const SUSPENSION_THRESHOLD = 2;

    public function __construct(
        protected SuspensionService $suspensionService
    ) {}

    /**
     * Apply the incident's strike once and enforce the V3 ladder tier.
     */
    public function applyForIncident(SafetyIncident $incident, ?User $appliedBy = null): ?InstructorStrike
    {
        $incident = $incident->fresh();

        if (
            ! $incident
            || ! $incident->isConfirmed()
            || $incident->isExternallyHandled()
            || $incident->final_outcome === IncidentOutcome::NoStrike
        ) {
            return null;
        }

        $existingStrike = InstructorStrike::where('safety_incident_id', $incident->id)->first();

        if ($existingStrike) {
            return $existingStrike;
        }

        if ($incident->strike_applied_at !== null) {
            return null;
        }

        $weight = $incident->strikes();

        if ($weight <= 0) {
            return null;
        }

        return DB::transaction(function () use ($incident, $appliedBy, $weight) {
            $lockedIncident = SafetyIncident::whereKey($incident->id)->lockForUpdate()->first();

            if (
                ! $lockedIncident
                || ! $lockedIncident->isConfirmed()
                || $lockedIncident->isExternallyHandled()
                || $lockedIncident->final_outcome === IncidentOutcome::NoStrike
                || $lockedIncident->strike_applied_at !== null
            ) {
                return null;
            }

            $profile = InstructorProfile::where('user_id', $lockedIncident->instructor_id)
                ->lockForUpdate()
                ->first();

            if (! $profile) {
                return null;
            }

            $existing = InstructorStrike::where('safety_incident_id', $lockedIncident->id)->first();

            if ($existing) {
                $lockedIncident->update(['strike_applied_at' => $lockedIncident->strike_applied_at ?? now()]);

                return $existing;
            }

            $category = $lockedIncident->categoryEnum();

            $strike = InstructorStrike::create([
                'instructor_id' => $lockedIncident->instructor_id,
                'safety_incident_id' => $lockedIncident->id,
                'weight' => $weight,
                'reason_code' => $lockedIncident->category,
                'reason_text' => $category?->label() ?? $lockedIncident->description,
                'source' => $lockedIncident->source ?? IncidentSource::Admin,
                'status' => StrikeStatus::Active,
                'applied_by' => $appliedBy?->id ?? $lockedIncident->confirmed_by,
                'applied_at' => now(),
            ]);

            $activeTotal = (int) InstructorStrike::where('instructor_id', $profile->user_id)
                ->where('status', StrikeStatus::Active->value)
                ->sum('weight');

            $profile->update(['strike_count' => $activeTotal]);

            $lockedIncident->update([
                'strike_applied_at' => now(),
                'resolved_at' => $lockedIncident->resolved_at ?? now(),
            ]);

            $this->applyPenaltyForTotal($profile->fresh(), $lockedIncident, $strike, $appliedBy);

            return $strike;
        });
    }

    /**
     * Revoke a confirmed incident's active strike and void any unpaid fine linked to it.
     * Call reconcileSuspensionAfterRevocation() afterwards to lift or downgrade active suspensions.
     */
    public function revokeIncident(SafetyIncident $incident, string $reason, ?User $revokedBy = null): bool
    {
        return DB::transaction(function () use ($incident, $reason, $revokedBy) {
            $lockedIncident = SafetyIncident::whereKey($incident->id)->lockForUpdate()->first();

            if (! $lockedIncident) {
                return false;
            }

            $profile = InstructorProfile::where('user_id', $lockedIncident->instructor_id)
                ->lockForUpdate()
                ->first();

            $strike = InstructorStrike::where('safety_incident_id', $lockedIncident->id)->first();

            if ($strike && $strike->status === StrikeStatus::Active) {
                $strike->update([
                    'status' => StrikeStatus::Revoked,
                    'revoked_by' => $revokedBy?->id,
                    'revoked_at' => now(),
                    'revocation_reason' => $reason,
                ]);
            }

            if ($profile) {
                $activeTotal = (int) InstructorStrike::where('instructor_id', $profile->user_id)
                    ->where('status', StrikeStatus::Active->value)
                    ->sum('weight');

                $profile->update(['strike_count' => $activeTotal]);
            }

            $fine = InstructorFine::where('safety_incident_id', $lockedIncident->id)
                ->lockForUpdate()
                ->first();

            if ($fine) {
                if ($fine->status === FineStatus::Due) {
                    $fine->update([
                        'status' => FineStatus::Voided,
                        'voided_at' => now(),
                        'void_reason' => $reason,
                    ]);
                } elseif ($fine->status === FineStatus::Paid) {
                    $note = trim(($fine->settlement_note ? $fine->settlement_note."\n" : '')
                        .'Incident revoked (check manual fine refund outside system): '.$reason);
                    $fine->update(['settlement_note' => $note]);
                }
            }

            $lockedIncident->update([
                'status' => IncidentStatus::Revoked,
                'resolved_at' => $lockedIncident->resolved_at ?? now(),
            ]);

            return true;
        });
    }

    /**
     * Reconcile an enforced automatic suspension after one or more strikes are revoked.
     */
    public function reconcileSuspensionAfterRevocation(
        User $instructor,
        ?User $admin = null,
        string $reason = 'Automatic suspension adjusted after strike revocation.'
    ): void {
        $profile = $instructor->instructorProfile()->first();

        if (! $profile) {
            return;
        }

        $suspension = InstructorSuspension::where('instructor_id', $instructor->id)
            ->where('type', SuspensionType::Automatic->value)
            ->enforced()
            ->latest()
            ->first();

        if (! $suspension) {
            return;
        }

        if ($profile->strike_count < self::TIER2_THRESHOLD) {
            $this->suspensionService->lift(
                $suspension,
                $reason,
                $admin,
                SuspensionStatus::RevokedByAdmin,
                false
            );

            return;
        }

        if ($profile->strike_count === self::TIER2_THRESHOLD && ($suspension->strike_tier ?? 3) >= 3) {
            $this->suspensionService->downgradeToTier2($suspension, $admin, $reason);
        }
    }

    public function activeStrikeCount(User $instructor): int
    {
        return (int) $instructor->instructorStrikes()
            ->where('status', StrikeStatus::Active->value)
            ->sum('weight');
    }

    public function summarizeActiveStrikes(User $instructor): string
    {
        $strikes = $instructor->instructorStrikes()
            ->with('incident')
            ->where('status', StrikeStatus::Active->value)
            ->orderByDesc('applied_at')
            ->get();

        if ($strikes->isEmpty()) {
            return 'No active strikes.';
        }

        $latest = $strikes->first();
        $latestReason = $latest->incident?->instructor_summary
            ?: ($latest->reason_text ?? $latest->reason_code ?? 'Confirmed concern');
        $latestDate = optional($latest->applied_at)->format('M d, Y') ?? now()->format('M d, Y');

        return sprintf(
            '%d active strike(s). Latest confirmed concern: %s (%d strike%s) on %s.',
            (int) $strikes->sum('weight'),
            $latestReason,
            $latest->weight,
            $latest->weight === 1 ? '' : 's',
            $latestDate
        );
    }

    /**
     * Mark all active strikes as served after a completed Tier 3 automatic suspension.
     */
    public function markServed(User $instructor): void
    {
        DB::transaction(function () use ($instructor) {
            $profile = InstructorProfile::where('user_id', $instructor->id)->lockForUpdate()->first();

            $instructor->instructorStrikes()
                ->where('status', StrikeStatus::Active->value)
                ->update([
                    'status' => StrikeStatus::Served,
                    'updated_at' => now(),
                ]);

            $profile?->update(['strike_count' => 0]);
        });
    }

    private function applyPenaltyForTotal(
        InstructorProfile $profile,
        SafetyIncident $incident,
        InstructorStrike $strike,
        ?User $appliedBy = null
    ): void {
        $total = (int) $profile->strike_count;

        if ($total <= 0) {
            return;
        }

        // Tier 1 (exact total = 1): official warning + ₱500 fine recorded as due.
        if ($total === self::TIER1_THRESHOLD) {
            InstructorFine::firstOrCreate(
                ['safety_incident_id' => $incident->id],
                [
                    'instructor_id' => $profile->user_id,
                    'instructor_strike_id' => $strike->id,
                    'amount' => (float) config('siasurf.strikes.fine_amount', 500),
                    'penalty_type' => 'warning_and_fine',
                    'status' => FineStatus::Due,
                    'reason' => $incident->instructor_summary ?: ($strike->reason_text ?? 'Confirmed safety concern'),
                    'issued_by' => $appliedBy?->id ?? $incident->confirmed_by,
                    'issued_at' => now(),
                ]
            );

            return;
        }

        $enforced = InstructorSuspension::where('instructor_id', $profile->user_id)
            ->enforced()
            ->lockForUpdate()
            ->latest()
            ->first();

        // Tier 2 (exact total = 2): 1-week (7-day) suspension.
        if ($total === self::TIER2_THRESHOLD) {
            if (! $enforced) {
                $this->suspensionService->suspendAutomatically(
                    $profile->user,
                    $incident,
                    $this->summarizeActiveStrikes($profile->user),
                    (int) config('siasurf.strikes.tier2_suspension_days', 7),
                    strikeTier: self::TIER2_THRESHOLD
                );
            }

            return;
        }

        // Tier 3 (total >= 3): 1-month (30-day) suspension + SISA ID confiscation notice.
        $tier3Days = (int) config('siasurf.strikes.tier3_suspension_days', 30);

        if ($enforced) {
            if ($enforced->type === SuspensionType::Automatic && ($enforced->strike_tier ?? 3) < self::TIER3_THRESHOLD) {
                $this->suspensionService->upgradeToTier3(
                    $enforced,
                    $incident,
                    $this->summarizeActiveStrikes($profile->user),
                    $tier3Days
                );
            }

            return;
        }

        $this->suspensionService->suspendAutomatically(
            $profile->user,
            $incident,
            $this->summarizeActiveStrikes($profile->user),
            $tier3Days,
            strikeTier: self::TIER3_THRESHOLD
        );
    }
}
