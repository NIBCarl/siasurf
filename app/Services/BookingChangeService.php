<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
use App\Events\BookingChangeRequested;
use App\Events\BookingChangeResolved;
use App\Exceptions\BookingChangeException;
use App\Exceptions\BookingRequestException;
use App\Exceptions\SafetyViolationException;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\SurfSpot;
use App\Models\User;
use App\Notifications\BookingChangeRequestedNotification;
use App\Notifications\BookingRescheduledNotification;
use App\Notifications\BookingSpotChangedNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FR 1.9.4 - instructors may cancel or reschedule their own bookings. Any change
 * made less than 24 hours before the lesson is parked for admin approval first;
 * anything further out is applied straight away and recorded for the audit trail.
 */
class BookingChangeService
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected BookingCancellationService $cancellationService,
    ) {
    }

    /**
     * File a cancellation request.
     */
    public function requestCancellation(
        Booking $booking,
        User $requester,
        ?string $reason = null,
        CancellationReason $cancellationReason = CancellationReason::Instructor
    ): BookingChangeRequest {
        $this->assertChangeable($booking);

        $allowedCause = in_array($cancellationReason, [
            CancellationReason::Instructor,
            CancellationReason::Weather,
        ], true) ? $cancellationReason : CancellationReason::Instructor;

        return DB::transaction(function () use ($booking, $requester, $reason, $allowedCause) {
            $changeRequest = $booking->changeRequests()->create([
                'requested_by' => $requester->id,
                'type' => ChangeRequestType::Cancellation,
                'status' => ChangeRequestStatus::Pending,
                'requires_admin_approval' => $booking->changeRequiresAdminApproval(),
                'reason' => $reason,
                'cancellation_reason' => $allowedCause,
                'original_date' => $booking->date,
                'original_time_period' => $booking->time_period?->value,
                'original_start_time' => $booking->start_time,
                'original_end_time' => $booking->end_time,
            ]);

            return $this->settle($changeRequest);
        });
    }

    /**
     * File a reschedule request for a new slot.
     *
     * @param  array{date:string,time_period:string,start_time:string,end_time:string}  $newSlot
     */
    public function requestReschedule(Booking $booking, User $requester, array $newSlot, ?string $reason = null): BookingChangeRequest
    {
        $this->assertChangeable($booking);

        // Keep the lesson length intact: derive the end time from the booking's
        // own duration instead of trusting whatever the form supplied.
        $durationHours = $booking->duration_hours ?: Booking::standardHours();
        $newSlot['end_time'] = Carbon::parse($newSlot['start_time'])
            ->addHours($durationHours)
            ->format('H:i');

        $this->assertSlotIsFree($booking, $newSlot);

        return DB::transaction(function () use ($booking, $requester, $newSlot, $reason) {
            $changeRequest = $booking->changeRequests()->create([
                'requested_by' => $requester->id,
                'type' => ChangeRequestType::Reschedule,
                'status' => ChangeRequestStatus::Pending,
                'requires_admin_approval' => $booking->changeRequiresAdminApproval(),
                'reason' => $reason,
                'original_date' => $booking->date,
                'original_time_period' => $booking->time_period?->value,
                'original_start_time' => $booking->start_time,
                'original_end_time' => $booking->end_time,
                'new_date' => $newSlot['date'],
                'new_time_period' => $newSlot['time_period'],
                'new_start_time' => $newSlot['start_time'],
                'new_end_time' => $newSlot['end_time'],
            ]);

            return $this->settle($changeRequest);
        });
    }

    /**
     * Auto-apply when no approval is needed, otherwise queue it for SISA.
     */
    private function settle(BookingChangeRequest $changeRequest): BookingChangeRequest
    {
        if ($changeRequest->requires_admin_approval) {
            $this->notifyAdmins($changeRequest);

            broadcast(new BookingChangeRequested(
                $changeRequest->fresh(['booking.student', 'booking.instructor', 'requestedBy'])
            ))->toOthers();

            return $changeRequest;
        }

        $changeRequest->resolve(ChangeRequestStatus::AutoApproved);
        $this->apply($changeRequest);

        broadcast(new BookingChangeResolved(
            $changeRequest->fresh(['booking.student', 'booking.instructor', 'requestedBy'])
        ))->toOthers();

        return $changeRequest;
    }

    /**
     * Admin approves a late change and it is applied immediately.
     */
    public function approve(BookingChangeRequest $changeRequest, User $admin, ?string $note = null): BookingChangeRequest
    {
        return DB::transaction(function () use ($changeRequest, $admin, $note) {
            $lockedRequest = BookingChangeRequest::query()
                ->whereKey($changeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRequest->isPending()) {
                throw new BookingChangeException('This request has already been resolved.');
            }

            $instructorId = Booking::query()->whereKey($lockedRequest->booking_id)->value('instructor_id');
            if ($instructorId) {
                User::query()->whereKey($instructorId)->lockForUpdate()->first();
            }

            $lockedBooking = Booking::with(['instructor.instructorProfile', 'surfSpot', 'participants'])
                ->whereKey($lockedRequest->booking_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedBooking->canBeChanged()) {
                throw new BookingChangeException(
                    'This booking can no longer be changed because it is ' . $lockedBooking->status->label() . '.'
                );
            }

            if (! $lockedRequest->isCancellation()) {
                $profile = $lockedBooking->instructor?->instructorProfile?->loadMissing('surfSpots');

                if ($profile) {
                    $service = $lockedBooking->session_type instanceof SessionType
                        ? $lockedBooking->session_type
                        : SessionType::tryFrom((string) $lockedBooking->session_type);

                    if ($service && ! $profile->offersService($service)) {
                        throw new BookingChangeException('The instructor no longer offers this service type.');
                    }

                    if ($lockedBooking->surfSpot && ! $profile->teachesAtSurfSpot($lockedBooking->surfSpot)) {
                        throw new BookingChangeException('The instructor is no longer authorized to teach at the scheduled surf spot.');
                    }

                    $safetyParticipants = $lockedBooking->participants->isNotEmpty()
                        ? $lockedBooking->participants->map(fn ($p) => [
                            'age' => $p->birthday?->age ?? $p->age,
                            'skill_level' => $p->skill_level,
                        ])->values()->all()
                        : [[
                            'age' => $lockedBooking->student_age,
                            'skill_level' => $lockedBooking->skill_level,
                        ]];

                    try {
                        app(SafetyService::class)->validateParticipants(
                            $safetyParticipants,
                            (int) ($profile->level?->value ?? 1),
                            (string) ($lockedBooking->surfSpot?->difficulty ?? 'beginner'),
                            $lockedBooking->student_id,
                            $service
                        );
                    } catch (SafetyViolationException $exception) {
                        throw new BookingChangeException($exception->getMessage(), 0, $exception);
                    }
                }

                $this->assertSlotIsFree($lockedBooking, [
                    'date' => $lockedRequest->new_date->format('Y-m-d'),
                    'time_period' => $lockedRequest->new_time_period?->value ?? (string) $lockedRequest->new_time_period,
                    'start_time' => $lockedRequest->new_start_time->format('H:i'),
                    'end_time' => $lockedRequest->new_end_time->format('H:i'),
                ]);
            }

            $lockedRequest->setRelation('booking', $lockedBooking);
            $lockedRequest->resolve(ChangeRequestStatus::Approved, $admin->id, $note);
            $this->apply($lockedRequest);

            DB::afterCommit(function () use ($lockedRequest) {
                broadcast(new BookingChangeResolved(
                    $lockedRequest->fresh(['booking.student', 'booking.instructor', 'requestedBy'])
                ))->toOthers();
            });

            return $lockedRequest->fresh();
        });
    }

    /**
     * Admin rejects a late change; the booking keeps its original slot.
     */
    public function reject(BookingChangeRequest $changeRequest, User $admin, ?string $note = null): BookingChangeRequest
    {
        if (!$changeRequest->isPending()) {
            throw new BookingChangeException('This request has already been resolved.');
        }

        return DB::transaction(function () use ($changeRequest, $admin, $note) {
            $changeRequest->resolve(ChangeRequestStatus::Rejected, $admin->id, $note);

            broadcast(new BookingChangeResolved(
                $changeRequest->fresh(['booking.student', 'booking.instructor', 'requestedBy'])
            ))->toOthers();

            return $changeRequest;
        });
    }

    /**
     * The requester pulls their own request back before SISA acts on it.
     */
    public function withdraw(BookingChangeRequest $changeRequest, User $requester): BookingChangeRequest
    {
        if (!$changeRequest->isPending()) {
            throw new BookingChangeException('This request has already been resolved.');
        }

        if ($changeRequest->requested_by !== $requester->id && !$requester->hasRole('admin')) {
            throw new BookingChangeException('Only the instructor who filed the request can withdraw it.');
        }

        return DB::transaction(function () use ($changeRequest) {
            $changeRequest->resolve(ChangeRequestStatus::Rejected, null, 'Withdrawn by the instructor.');

            broadcast(new BookingChangeResolved(
                $changeRequest->fresh(['booking.student', 'booking.instructor', 'requestedBy'])
            ))->toOthers();

            return $changeRequest;
        });
    }

    /**
     * Push an approved change onto the booking itself.
     */
    private function apply(BookingChangeRequest $changeRequest): void
    {
        $booking = $changeRequest->booking;

        if (!$booking) {
            return;
        }

        if ($changeRequest->isCancellation()) {
            // The student must see the instructor's explanation, plus any note
            // SISA added when approving the late cancellation.
            $note = trim((string) $changeRequest->reason);

            if ($changeRequest->admin_note) {
                $note = trim(
                    $note
                    . ($note !== '' ? "\n\n" : '')
                    . 'SISA note: ' . $changeRequest->admin_note
                );
            }

            if ($note === '') {
                $note = 'The instructor cancelled this lesson without providing a reason.';
            }

            $reason = $changeRequest->cancellation_reason ?? CancellationReason::Instructor;
            $actor = $changeRequest->reviewedBy ?? $changeRequest->requestedBy;

            $this->cancellationService->cancel($booking, $reason, $note, $actor);
        } else {
            $this->applyReschedule($changeRequest, $booking);
        }

        $changeRequest->markApplied();
    }

    /**
     * Move a booking to the requested slot and relocate its availability lock.
     */
    private function applyReschedule(BookingChangeRequest $changeRequest, Booking $booking): void
    {
        $oldDate = $this->availabilityService->dateOf($booking);
        $oldPeriod = $this->availabilityService->timePeriodOf($booking);

        $booking->update([
            'original_date' => $booking->original_date ?? $booking->date,
            'date' => $changeRequest->new_date,
            'time_period' => $changeRequest->new_time_period,
            'start_time' => $changeRequest->new_start_time?->format('H:i'),
            'end_time' => $changeRequest->new_end_time?->format('H:i'),
            'rescheduled_by_instructor_at' => now(),
            'student_accepted_reschedule_at' => null,
        ]);

        $booking->refresh();

        // FR 1.8.3 - old slot is freed, new slot is locked.
        $this->availabilityService->moveBookingSlot($booking, $oldDate, $oldPeriod);

        DB::afterCommit(function () use ($booking, $changeRequest) {
            $fresh = $booking->fresh(['instructor', 'surfSpot', 'student']);
            $fresh?->student?->notify(new BookingRescheduledNotification(
                $fresh,
                $changeRequest->reason,
                $changeRequest->admin_note
            ));
        });
    }

    /**
     * Every SISA account hears about a change that needs its sign-off.
     */
    private function notifyAdmins(BookingChangeRequest $changeRequest): void
    {
        User::query()->admins()->get()->each(
            fn (User $admin) => $admin->notify(new BookingChangeRequestedNotification($changeRequest))
        );
    }

    /**
     * Guard the states where a change no longer makes sense.
     */
    private function assertChangeable(Booking $booking): void
    {
        if (!$booking->canBeChanged()) {
            throw new BookingChangeException(
                'This booking can no longer be changed because it is ' . $booking->status->label() . '.'
            );
        }

        if ($booking->hasPendingChangeRequest()) {
            throw new BookingChangeException('A change request for this booking is already awaiting review.');
        }
    }

    /**
     * Make sure the requested slot is actually bookable.
     *
     * @param  array{date:string,time_period:string,start_time:string,end_time:string}  $slot
     */
    private function assertSlotIsFree(Booking $booking, array $slot): void
    {
        if (Carbon::parse($slot['date'])->isBefore(today())) {
            throw new BookingChangeException('The new lesson date must be today or later.');
        }

        $manualBlock = Availability::query()
            ->where('user_id', $booking->instructor_id)
            ->whereDate('specific_date', $slot['date'])
            ->where('time_period', $slot['time_period'])
            ->manualBlock()
            ->exists();

        if ($manualBlock) {
            throw new BookingChangeException('You blocked this date as personal time. Unblock it first, then reschedule.');
        }

        // Capacity-aware: another lesson may share the slot as long as the
        // instructor's student capacity, skill level, service type, and spot
        // are compatible. The booking being moved is excluded so it never
        // conflicts with itself.
        $bookingSkill = $booking->skill_level instanceof SkillLevel
            ? $booking->skill_level
            : SkillLevel::tryFrom((string) $booking->skill_level);
        $bookingService = $booking->session_type instanceof SessionType
            ? $booking->session_type
            : SessionType::tryFrom((string) $booking->session_type);

        try {
            $this->availabilityService->assertRangeIsBookable(
                $booking->instructor_id,
                $slot['date'],
                $slot['start_time'],
                $slot['end_time'],
                max(1, (int) $booking->student_count),
                $booking->id,
                $bookingSkill,
                $bookingService,
                $booking->surf_spot_id ? (int) $booking->surf_spot_id : null
            );
        } catch (BookingRequestException $exception) {
            throw new BookingChangeException($exception->getMessage());
        }
    }

    /**
     * Move this booking (and any overlapping bookings sharing the same instructor
     * session) to a new surf spot and notify every affected student with the
     * instructor's reason.
     *
     * Safety Rule #5 is never bypassed: the new spot's difficulty must remain
     * safe for every surfer in the session and be offered by the instructor.
     *
     * @return Collection<int, Booking>
     */
    public function updateSurfSpot(Booking $booking, SurfSpot $newSpot, string $reason, User $instructor): Collection
    {
        if ($booking->instructor_id !== $instructor->id) {
            throw new BookingChangeException('You are not authorized to change the surf spot for this booking.');
        }

        if (! $booking->canChangeSurfSpot()) {
            throw new BookingChangeException(
                'The surf spot can no longer be updated because this booking is ' . $booking->status->label() . '.'
            );
        }

        if (! $newSpot->is_active) {
            throw new BookingChangeException('The selected surf spot is currently unavailable.');
        }

        if ($newSpot->id === $booking->surf_spot_id) {
            throw new BookingChangeException("This lesson is already scheduled at {$newSpot->name}.");
        }

        $profile = $instructor->instructorProfile?->loadMissing('surfSpots');

        if (! $profile || ! $profile->teachesAtSurfSpot($newSpot) || ! $profile->level->canTeachAtDifficulty((string) $newSpot->difficulty)) {
            throw new BookingChangeException("You are not authorized to teach at {$newSpot->name}.");
        }

        $cleanReason = trim($reason);

        if ($cleanReason === '') {
            throw new BookingChangeException('Please provide a reason for changing the surf spot.');
        }

        return DB::transaction(function () use ($booking, $newSpot, $cleanReason, $instructor) {
            User::query()->whereKey($instructor->id)->lockForUpdate()->first();

            $lockedBooking = Booking::with('surfSpot')
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedBooking->canChangeSurfSpot()) {
                throw new BookingChangeException(
                    'The surf spot can no longer be updated because this booking is ' . $lockedBooking->status->label() . '.'
                );
            }

            $group = $this->availabilityService->overlappingSessionBookings($lockedBooking);

            foreach ($group as $member) {
                $memberSkill = $member->skill_level instanceof SkillLevel
                    ? $member->skill_level
                    : (SkillLevel::tryFrom((string) $member->skill_level) ?? SkillLevel::Beginner);

                if (! $memberSkill->canSurfDifficulty((string) $newSpot->difficulty)) {
                    throw new BookingChangeException(sprintf(
                        'Safety rule violation: %s surfers in this session cannot surf %s breaks (%s is rated %s).',
                        $memberSkill->label(),
                        $newSpot->difficulty,
                        $newSpot->name,
                        ucfirst((string) $newSpot->difficulty)
                    ));
                }
            }

            $previousSpots = [];

            foreach ($group as $member) {
                $previousSpots[$member->id] = $member->surfSpot?->name ?? 'Previous surf spot';

                $member->update([
                    'previous_surf_spot_id' => $member->surf_spot_id,
                    'surf_spot_id' => $newSpot->id,
                    'spot_change_reason' => $cleanReason,
                    'spot_changed_at' => now(),
                ]);
            }

            DB::afterCommit(function () use ($group, $previousSpots, $newSpot, $cleanReason) {
                foreach ($group as $member) {
                    $fresh = $member->fresh(['instructor', 'surfSpot', 'student', 'previousSurfSpot']);
                    $fresh?->student?->notify(new BookingSpotChangedNotification(
                        $fresh,
                        $previousSpots[$member->id],
                        $newSpot->name,
                        $cleanReason
                    ));
                }
            });

            return $group;
        });
    }
}