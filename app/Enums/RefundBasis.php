<?php

namespace App\Enums;

enum RefundBasis: string
{
    case LongTermWithin30Days = 'long_term_within_30d';
    case LongTermFallbackFull = 'long_term_fallback_7_plus_days';
    case LongTermFallbackHalf = 'long_term_fallback_3_to_6_days';
    case LongTermFallbackNoRefund = 'long_term_fallback_under_3_days';
    case ShortTermFull = 'short_term_7_plus_days';
    case ShortTermHalf = 'short_term_3_to_6_days';
    case ShortTermNoRefund = 'short_term_under_3_days';
    case InstructorCancelled = 'instructor_cancelled';
    case WeatherSafety = 'weather_safety';
    case InstructorNoShow = 'instructor_no_show';
    case InstructorSuspended = 'instructor_suspended';
    case InstructorRescheduleDeclined = 'instructor_reschedule_declined';
    case StudentNoShow = 'student_no_show';
    case CashUncollected = 'cash_uncollected';
    case PendingUnpaid = 'pending_unpaid';
    case AdminOverride = 'admin_override';

    public function label(): string
    {
        return match ($this) {
            self::LongTermWithin30Days => 'Long-term booking (within 30 days of payment)',
            self::LongTermFallbackFull => 'Long-term fall-through (7+ days before session)',
            self::LongTermFallbackHalf => 'Long-term fall-through (3–6 days before session)',
            self::LongTermFallbackNoRefund => 'Long-term fall-through (under 3 days before session)',
            self::ShortTermFull => 'Short-term booking (7+ days before session)',
            self::ShortTermHalf => 'Short-term booking (3–6 days before session)',
            self::ShortTermNoRefund => 'Short-term booking (under 3 days before session)',
            self::InstructorCancelled => 'Cancelled by instructor (100% lesson price)',
            self::WeatherSafety => 'Cancelled due to unsafe weather or sea conditions',
            self::InstructorNoShow => 'Instructor did not arrive (100% lesson price)',
            self::InstructorSuspended => 'Instructor account suspended (100% lesson price)',
            self::InstructorRescheduleDeclined => 'Instructor rescheduled — student declined / cancelled',
            self::StudentNoShow => 'Student no-show (no refund)',
            self::CashUncollected => 'Cash booking cancelled (no amount collected)',
            self::PendingUnpaid => 'Unpaid request withdrawn (no charge)',
            self::AdminOverride => 'SISA Administrator refund decision',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LongTermWithin30Days => '100% of the lesson price is refunded (7% service fee retained).',
            self::LongTermFallbackFull,
            self::ShortTermFull => '100% of the lesson price is refunded when cancelled 7 or more days before the lesson (7% service fee retained).',
            self::LongTermFallbackHalf,
            self::ShortTermHalf => '50% of the lesson price is refunded when cancelled 3 to 6 days before the lesson; 50% goes to the instructor and the 7% service fee is retained.',
            self::LongTermFallbackNoRefund,
            self::ShortTermNoRefund => 'No refund applies less than 3 days before the lesson; the lesson price goes to the instructor and the 7% service fee is retained.',
            self::InstructorCancelled,
            self::WeatherSafety,
            self::InstructorNoShow,
            self::InstructorSuspended => '100% of the lesson price is refunded for instructor-side or safety cancellations (7% service fee retained).',
            self::InstructorRescheduleDeclined => 'Because your instructor moved the lesson date, you receive 100% of the lesson price back (7% service fee retained).',
            self::StudentNoShow => 'No refund is issued for a missed session without prior cancellation.',
            self::CashUncollected => 'No cash was collected, so no refund or service fee applies.',
            self::PendingUnpaid => 'No payment was completed, so withdrawing releases your slot with no charge.',
            self::AdminOverride => 'Refund issued by SISA Administration.',
        };
    }
}
