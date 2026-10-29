<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Events\BookingCancelled;
use App\Events\BookingChangeRequested;
use App\Events\BookingChangeResolved;
use App\Exceptions\BookingChangeException;
use App\Exceptions\BookingRequestException;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\BookingChangeRequestedNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR 1.9.4 - instructors may cancel or reschedule their own bookings. Any change
 * made less than 24 hours before the lesson is parked for admin approval first;
 * anything further out is applied straight away and recorded for the audit trail.
 */
class BookingChangeService
{
    public function __construct(
        protected AvailabilityService $availabilityService
    ) {
    }

    /**
     * File a cancellation request.
     */
    public function requestCancellation(Booking $booking, User $requester, ?string $reason = null): BookingChangeRequest
    {
        $this->assertChangeable($booking);

        return DB::transaction(function () use ($booking, $requester, $reason) {
            $changeRequest = $booking->changeRequests()->create([
                'requested_by' => $requester->id,
                'type' => ChangeRequestType::Cancellation,
                'status' => ChangeRequestStatus::Pending,
                'requires_admin_approval' => $booking->changeRequiresAdminApproval(),
                'reason' => $reason,
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
        if (!$changeRequest->isPending()) {
            throw new BookingChangeException('This request has already been resolved.');
        }

        return DB::transaction(function () use ($changeRequest, $admin, $note) {
            $changeRequest->resolve(ChangeRequestStatus::Approved, $admin->id, $note);
            $this->apply($changeRequest);

            broadcast(new BookingChangeResolved(
                $changeRequest->fresh(['booking.student', 'booking.instructor', 'requestedBy'])
            ))->toOthers();

            return $changeRequest;
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
            $oldDate = $this->availabilityService->dateOf($booking);
            $oldPeriod = $this->availabilityService->timePeriodOf($booking);

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

            $booking->cancel($note, \App\Enums\CancellationReason::Instructor);

            // FR 1.8.3 - hand the slot back now that the lesson is gone.
            $this->availabilityService->releaseSlot($booking->instructor_id, $oldDate, $oldPeriod, $booking->id);

            $booking->refresh();

            broadcast(new BookingCancelled($booking->load(['student', 'instructor', 'surfSpot']), $note))
                ->toOthers();

            foreach ([$booking->student, $booking->instructor] as $party) {
                $party?->notify(new BookingCancelledNotification($booking, $note));
            }
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
            'date' => $changeRequest->new_date,
            'time_period' => $changeRequest->new_time_period,
            'start_time' => $changeRequest->new_start_time?->format('H:i'),
            'end_time' => $changeRequest->new_end_time?->format('H:i'),
        ]);

        $booking->refresh();

        // FR 1.8.3 - old slot is freed, new slot is locked.
        $this->availabilityService->moveBookingSlot($booking, $oldDate, $oldPeriod);
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
        // instructor's student capacity is not exceeded. The booking being
        // moved is excluded so it never conflicts with itself.
        try {
            $this->availabilityService->assertRangeIsBookable(
                $booking->instructor_id,
                $slot['date'],
                $slot['start_time'],
                $slot['end_time'],
                max(1, (int) $booking->student_count),
                $booking->id
            );
        } catch (BookingRequestException $exception) {
            throw new BookingChangeException($exception->getMessage());
        }
    }
}