<?php

namespace App\Enums;

/**
 * Why a booking ended up cancelled. Kept separate from BookingStatus so the
 * status list stays a small set of states while the cause stays queryable for
 * reporting and refund decisions.
 */
enum CancellationReason: string
{
    case Student = 'student';
    case Instructor = 'instructor';
    case Admin = 'admin';
    case Weather = 'weather';
    case PaymentTimeout = 'payment_timeout';
    case InstructorNoShow = 'instructor_no_show';
    case StudentNoShow = 'student_no_show';
    case InstructorSuspended = 'instructor_suspended';
    case RescheduleDeclined = 'reschedule_declined';

    public function label(): string
    {
        return match($this) {
            self::Student => 'Cancelled by student',
            self::Instructor => 'Cancelled by instructor',
            self::Admin => 'Cancelled by SISA',
            self::Weather => 'Cancelled - weather or safety',
            self::PaymentTimeout => 'Payment window expired',
            self::InstructorNoShow => 'Instructor did not arrive',
            self::StudentNoShow => 'Student did not arrive',
            self::InstructorSuspended => 'Instructor account suspended',
            self::RescheduleDeclined => 'Student declined rescheduled date',
        };
    }

    /**
     * Cancellations caused by safety, instructor action/suspension, or a
     * declined instructor reschedule always qualify for a full lesson-price
     * refund (retaining only the 7% service fee).
     */
    public function isRefundable(): bool
    {
        return in_array($this, [
            self::Instructor,
            self::Admin,
            self::Weather,
            self::InstructorNoShow,
            self::InstructorSuspended,
            self::RescheduleDeclined,
        ], true);
    }

    /**
     * Cancellations that should be charged against the instructor's
     * reliability record rather than their safety strike count.
     */
    public function countsAgainstInstructor(): bool
    {
        return in_array($this, [self::Instructor, self::InstructorNoShow], true);
    }
}