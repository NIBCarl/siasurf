<?php

namespace App\Services;

use App\Enums\CancellationReason;
use App\Enums\InstructorStatus;
use App\Enums\SuspensionStatus;
use App\Enums\SuspensionType;
use App\Events\BookingCancelled;
use App\Events\InstructorStatusChanged;
use App\Models\Booking;
use App\Models\InstructorProfile;
use App\Models\InstructorStatusLog;
use App\Models\InstructorSuspension;
use App\Models\SafetyIncident;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\InstructorReactivated;
use App\Notifications\InstructorSuspended;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SuspensionService
{
    public function __construct(
        protected AvailabilityService $availabilityService
    ) {
    }

    public function suspendManually(User $instructor, string $reason, int $days, User $admin): InstructorSuspension
    {
        return $this->suspend($instructor, SuspensionType::Manual, $reason, $days, $admin, null);
    }

    public function suspendAutomatically(
        User $instructor,
        SafetyIncident $incident,
        string $reason,
        int $days = 30
    ): InstructorSuspension {
        return $this->suspend($instructor, SuspensionType::Automatic, $reason, $days, null, $incident);
    }

    private function suspend(
        User $instructor,
        SuspensionType $type,
        string $reason,
        int $days,
        ?User $admin,
        ?SafetyIncident $incident
    ): InstructorSuspension {
        $oldStatus = null;

        $suspension = DB::transaction(function () use ($instructor, $type, $reason, $days, $admin, $incident, &$oldStatus) {
            $profile = InstructorProfile::where('user_id', $instructor->id)
                ->lockForUpdate()
                ->firstOrFail();

            $active = InstructorSuspension::where('instructor_id', $instructor->id)
                ->where('status', SuspensionStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if ($active) {
                return $active;
            }

            $oldStatus = $profile->status;
            $startsAt = now();
            $endsAt = $startsAt->copy()->addDays(max(1, $days));

            $suspension = InstructorSuspension::create([
                'instructor_id' => $instructor->id,
                'type' => $type,
                'status' => SuspensionStatus::Active,
                'reason' => $reason,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
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
            $instructor->notify(new InstructorSuspended($reason, max(1, $days)));
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

    public function lift(
        InstructorSuspension $suspension,
        string $reason,
        ?User $admin = null,
        SuspensionStatus $status = SuspensionStatus::Completed,
        bool $resetStrikes = false
    ): InstructorSuspension {
        $oldStatus = null;

        $suspension = DB::transaction(function () use ($suspension, $reason, $admin, $status, $resetStrikes, &$oldStatus) {
            $locked = InstructorSuspension::whereKey($suspension->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SuspensionStatus::Active) {
                return $locked;
            }

            $profile = InstructorProfile::where('user_id', $locked->instructor_id)
                ->lockForUpdate()
                ->first();

            if (! $profile) {
                return $locked;
            }

            $oldStatus = $profile->status;

            $locked->update([
                'status' => $status,
                'ends_at' => $status === SuspensionStatus::Completed
                    ? ($locked->ends_at ?? now())
                    : now(),
            ]);

            $profile->update([
                'status' => InstructorStatus::Active,
                'suspended_until' => null,
                'suspension_reason' => null,
                'suspended_at' => null,
            ]);

            if ($resetStrikes) {
                $profile->user->instructorStrikes()
                    ->where('status', \App\Enums\StrikeStatus::Active->value)
                    ->update([
                        'status' => \App\Enums\StrikeStatus::Served,
                        'updated_at' => now(),
                    ]);

                $profile->update(['strike_count' => 0]);
            }

            if ($status === SuspensionStatus::Completed) {
                $locked->appeals()
                    ->where('status', \App\Enums\AppealStatus::Pending->value)
                    ->update([
                        'status' => \App\Enums\AppealStatus::Expired,
                        'reviewed_at' => now(),
                        'admin_response' => 'The suspension period ended before the appeal was decided.',
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
                \App\Enums\BookingStatus::Pending->value,
                \App\Enums\BookingStatus::AwaitingPayment->value,
                \App\Enums\BookingStatus::Confirmed->value,
            ])
            ->whereDate('date', '>=', today())
            ->get();

        foreach ($bookings as $booking) {
            try {
                $date = $this->availabilityService->dateOf($booking);
                $period = $this->availabilityService->timePeriodOf($booking);

                $booking->cancel(
                    'Instructor account suspended: ' . $reason,
                    CancellationReason::InstructorSuspended
                );

                $this->availabilityService->releaseSlot(
                    $instructor->id,
                    $date,
                    $period,
                    $booking->id
                );

                $fresh = $booking->fresh(['student', 'instructor', 'surfSpot']);

                broadcast(new BookingCancelled(
                    $fresh,
                    'Instructor account suspended.'
                ))->toOthers();

                $fresh->student?->notify(new BookingCancelledNotification(
                    $fresh,
                    'The instructor account was suspended. SISA will assist with rebooking or a refund.'
                ));
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
