<?php

namespace App\Services;

use App\Enums\AppealStatus;
use App\Enums\IncidentStatus;
use App\Enums\SuspensionStatus;
use App\Enums\SuspensionType;
use App\Models\InstructorAppeal;
use App\Models\InstructorSuspension;
use App\Models\SafetyIncident;
use App\Models\User;
use App\Notifications\AppealResolvedNotification;
use App\Notifications\AppealSubmittedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppealService
{
    public function __construct(
        protected StrikeService $strikeService,
        protected SuspensionService $suspensionService
    ) {}

    public function submit(User $instructor, InstructorSuspension $suspension, array $data): InstructorAppeal
    {
        $appeal = DB::transaction(function () use ($instructor, $suspension, $data) {
            $lockedSuspension = InstructorSuspension::whereKey($suspension->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedSuspension->instructor_id !== $instructor->id) {
                throw ValidationException::withMessages([
                    'appeal' => 'You can only appeal your own suspension.',
                ]);
            }

            if (! $lockedSuspension->isEnforced()) {
                throw ValidationException::withMessages([
                    'appeal' => 'This suspension is no longer active.',
                ]);
            }

            if ($lockedSuspension->appeals()->exists()) {
                throw ValidationException::withMessages([
                    'appeal' => 'An appeal has already been filed for this suspension.',
                ]);
            }

            return InstructorAppeal::create([
                'instructor_id' => $instructor->id,
                'instructor_suspension_id' => $lockedSuspension->id,
                'message' => $data['message'],
                'ground' => $data['ground'] ?? null,
                'evidence_path' => $data['evidence_path'] ?? null,
                'status' => AppealStatus::Pending,
            ]);
        });

        User::where('role', 'admin')->get()->each(function (User $admin) use ($appeal) {
            try {
                $admin->notify(new AppealSubmittedNotification($appeal));
            } catch (\Throwable $exception) {
                \Log::warning('Failed to notify admin about appeal.', [
                    'appeal_id' => $appeal->id,
                    'admin_id' => $admin->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });

        return $appeal;
    }

    public function approve(
        InstructorAppeal $appeal,
        User $admin,
        string $response,
        array $revokeIncidentIds = []
    ): InstructorAppeal {
        if (! $appeal->isPending()) {
            throw ValidationException::withMessages([
                'appeal' => 'This appeal has already been decided.',
            ]);
        }

        $appeal = DB::transaction(function () use ($appeal, $admin, $response, $revokeIncidentIds) {
            $locked = InstructorAppeal::whereKey($appeal->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'appeal' => 'This appeal has already been decided.',
                ]);
            }

            $suspension = $locked->instructor_suspension_id
                ? InstructorSuspension::whereKey($locked->instructor_suspension_id)->lockForUpdate()->first()
                : null;

            if (! $suspension || ! $suspension->isEnforced()) {
                throw ValidationException::withMessages([
                    'appeal' => 'This suspension is no longer active.',
                ]);
            }

            $revoked = [];

            foreach ($revokeIncidentIds as $incidentId) {
                $incident = SafetyIncident::whereKey($incidentId)
                    ->where('instructor_id', $locked->instructor_id)
                    ->where('status', IncidentStatus::Confirmed->value)
                    ->first();

                if (! $incident) {
                    continue;
                }

                $this->strikeService->revokeIncident(
                    $incident,
                    'Revoked on appeal #'.$locked->id.': '.$response,
                    $admin
                );

                $revoked[] = $incident->id;
            }

            $locked->update([
                'status' => AppealStatus::Approved,
                'admin_response' => $response,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'revoked_incident_ids' => $revoked,
            ]);

            $profile = $locked->instructor->instructorProfile()->first();

            if ($suspension && $suspension->type === SuspensionType::Automatic && $profile) {
                $remainingStrikes = (int) $profile->strike_count;
                $tier = (int) ($suspension->strike_tier ?? StrikeService::TIER3_THRESHOLD);

                if ($remainingStrikes >= $tier) {
                    throw ValidationException::withMessages([
                        'revoke_incident_ids' => $tier >= StrikeService::TIER3_THRESHOLD
                            ? 'Select enough incidents to bring the active strike count below 3 (below 2 to lift the suspension completely).'
                            : 'Select enough incidents to bring the active strike count below 2 before approving this appeal.',
                    ]);
                }

                if ($tier >= StrikeService::TIER3_THRESHOLD && $remainingStrikes === StrikeService::TIER2_THRESHOLD) {
                    $this->suspensionService->downgradeToTier2(
                        $suspension,
                        $admin,
                        'Downgraded to 1-week suspension on appeal #'.$locked->id.': '.$response
                    );
                } elseif ($suspension->isEnforced()) {
                    $this->suspensionService->lift(
                        $suspension,
                        'Suspension lifted on appeal #'.$locked->id.'.',
                        $admin,
                        SuspensionStatus::LiftedOnAppeal,
                        false
                    );
                }
            } elseif ($suspension && $suspension->isEnforced()) {
                $this->suspensionService->lift(
                    $suspension,
                    'Suspension lifted on appeal #'.$locked->id.'.',
                    $admin,
                    SuspensionStatus::LiftedOnAppeal,
                    false
                );
            }

            return $locked->fresh();
        });

        try {
            $appeal->instructor->notify(new AppealResolvedNotification($appeal));
        } catch (\Throwable $exception) {
            \Log::warning('Failed to notify instructor about appeal approval.', [
                'appeal_id' => $appeal->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $appeal;
    }

    public function reject(InstructorAppeal $appeal, User $admin, string $response): InstructorAppeal
    {
        if (! $appeal->isPending()) {
            throw ValidationException::withMessages([
                'appeal' => 'This appeal has already been decided.',
            ]);
        }

        $appeal->update([
            'status' => AppealStatus::Rejected,
            'admin_response' => $response,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        try {
            $appeal->instructor->notify(new AppealResolvedNotification($appeal->fresh()));
        } catch (\Throwable $exception) {
            \Log::warning('Failed to notify instructor about appeal rejection.', [
                'appeal_id' => $appeal->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $appeal->fresh();
    }

    public function withdraw(InstructorAppeal $appeal): InstructorAppeal
    {
        if (! $appeal->isPending()) {
            throw ValidationException::withMessages([
                'appeal' => 'Only a pending appeal can be withdrawn.',
            ]);
        }

        $appeal->update([
            'status' => AppealStatus::Withdrawn,
            'reviewed_at' => now(),
        ]);

        return $appeal->fresh();
    }
}
