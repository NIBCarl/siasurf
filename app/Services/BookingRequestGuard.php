<?php

namespace App\Services;

use App\Exceptions\BookingRequestException;
use App\Models\Booking;
use App\Models\User;

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
        $exists = Booking::query()
            ->where('student_id', $student->id)
            ->forSlot(
                (int) $slot['instructor_id'],
                (string) $slot['date'],
                (string) $slot['time_period']
            )
            ->open()
            ->exists();

        if ($exists) {
            throw new BookingRequestException(
                'You already have an open request for this instructor at that time. '
                . 'Wait for a response before requesting it again.'
            );
        }
    }

    /**
     * Cap the total number of unanswered requests a student can hold, so a
     * single account cannot tie up many slots at once.
     */
    private function assertWithinOpenRequestLimit(User $student): void
    {
        $open = Booking::query()
            ->where('student_id', $student->id)
            ->open()
            ->count();

        if ($open >= Booking::MAX_OPEN_REQUESTS_PER_STUDENT) {
            throw new BookingRequestException(
                'You already have ' . $open . ' booking requests waiting for a response. '
                . 'Please wait for those to be answered before requesting another lesson.'
            );
        }
    }
}