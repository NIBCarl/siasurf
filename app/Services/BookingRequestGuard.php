<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\BookingRequestException;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Abuse prevention for the two-phase flow.
 *
 * Without this a student can fire off requests to several instructors for the
 * same Friday morning slot, take whichever confirms first, and abandon the
 * rest - leaving other instructors holding a slot that never converts.
 */
class BookingRequestGuard
{
    /**
     * Verify the student is allowed to file this request.
     *
     * @param  array{instructor_id:int|string,date:string,time_period:string}  $slot
     *
     * @throws BookingRequestException
     */
    public function assertStudentMayRequest(User $student, array $slot): void
    {
        $this->assertNoDuplicateSlotRequest($student, $slot);
        $this->assertWithinOpenRequestLimit($student);
    }

    /**
     * Only one live request may exist per student, per instructor, per slot.
     *
     * @param  array{instructor_id:int|string,date:string,time_period:string}  $slot
     */
    private function assertNoDuplicateSlotRequest(User $student, array $slot): void
    {
        $exists = $this->unpaidActiveRequestsQuery($student)
            ->forSlot(
                (int) $slot['instructor_id'],
                (string) $slot['date'],
                (string) $slot['time_period']
            )
            ->exists();

        if ($exists) {
            throw new BookingRequestException(
                'You already have an open or unpaid booking for this instructor at that time. '
                . 'Complete or wait for that booking before requesting it again.'
            );
        }
    }

    /**
     * Cap the total number of unanswered or unpaid upcoming cash bookings a
     * student can hold, so a single account cannot tie up many slots at once.
     */
    private function assertWithinOpenRequestLimit(User $student): void
    {
        $open = $this->unpaidActiveRequestsQuery($student)->count();

        if ($open >= Booking::MAX_OPEN_REQUESTS_PER_STUDENT) {
            throw new BookingRequestException(
                'You already have ' . $open . ' open or unpaid booking requests. '
                . 'Please complete or wait for those before requesting another lesson.'
            );
        }
    }

    private function unpaidActiveRequestsQuery(User $student): Builder
    {
        return Booking::query()
            ->where('student_id', $student->id)
            ->where(function (Builder $query) {
                $query->open()
                    ->orWhere(function (Builder $cashHold) {
                        $cashHold->where('status', BookingStatus::Confirmed)
                            ->where('payment_status', PaymentStatus::PendingCash->value)
                            ->whereDate('date', '>=', today());
                    });
            });
    }
}