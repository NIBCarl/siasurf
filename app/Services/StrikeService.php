<?php

namespace App\Services;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\StrikeStatus;
use App\Models\InstructorProfile;
use App\Models\InstructorStrike;
use App\Models\SafetyIncident;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for strike application and revocation.
 *
 * A strike only exists here after an incident is CONFIRMED. Student reports
 * start as pending, so they cannot affect an instructor until SISA reviews
 * them. The ledger is append-only; revocation marks a row instead of deleting
 * history.
 */
class StrikeService
{
    public const SUSPENSION_THRESHOLD = 3;

    public function __construct(
        protected SuspensionService $suspensionService
    ) {
    }

    /**
     * Apply the incident's strike once. Safe to call repeatedly.
     */
    public function applyForIncident(SafetyIncident $incident, ?User $appliedBy = null): ?InstructorStrike
    {
        $incident = $incident->fresh();

        if (! $incident || ! $incident->isConfirmed()) {
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

            if (! $lockedIncident || ! $lockedIncident->isConfirmed() || $lockedIncident->strike_applied_at !== null) {
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
                'applied_by' => $appliedBy?->id,
                'applied_at' => now(),
            ]);

            $profile->increment('strike_count', $weight);

            $lockedIncident->update([
                'strike_applied_at' => now(),
                'resolved_at' => $lockedIncident->resolved_at ?? now(),
            ]);

            $this->logStrikeThreshold($profile, $lockedIncident);

            return $strike;
        });
    }

    /**
     * Revoke a confirmed incident's active strike. The caller decides whether
     * to lift any suspension that resulted from it.
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

                if ($profile) {
                    $profile->update([
                        'strike_count' => max(0, $profile->strike_count - $strike->weight),
                    ]);
                }
            }

            $lockedIncident->update([
                'status' => IncidentStatus::Revoked,
                'resolved_at' => $lockedIncident->resolved_at ?? now(),
            ]);

            return true;
        });
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
        $latestReason = $latest->reason_text ?? $latest->reason_code ?? 'Confirmed concern';
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
     * Mark all active strikes as served after a completed automatic suspension.
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

    private function logStrikeThreshold(InstructorProfile $profile, SafetyIncident $incident): void
    {
        if ($profile->strike_count < self::SUSPENSION_THRESHOLD) {
            return;
        }

        if ($profile->hasActiveSuspension()) {
            return;
        }

        $this->suspensionService->suspendAutomatically(
            $profile->user,
            $incident,
            $this->summarizeActiveStrikes($profile->user),
            (int) config('siasurf.strikes.suspension_days', 30)
        );
    }
}

