<?php

namespace App\Services;

use App\Data\RefundCalculation;
use App\Enums\CancellationReason;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundBasis;
use App\Models\Booking;
use App\Models\Payment;
use Carbon\Carbon;

class RefundCalculatorService
{
    /**
     * Calculate refund eligibility, percentages, retained service fee, and
     * instructor earnings retention for a booking cancellation or no-show.
     */
    public function calculate(
        Booking $booking,
        CancellationReason $reason,
        ?Carbon $cancelledAt = null
    ): RefundCalculation {
        $tz = config('app.timezone', 'Asia/Manila');
        $cancelMoment = ($cancelledAt ?? now())->copy()->timezone($tz);
        $cancelDay = $cancelMoment->copy()->startOfDay();

        $payment = $booking->payment;
        $lessonPrice = $booking->effectiveLessonPrice();
        $serviceFee = $booking->effectiveServiceFeeAmount();

        // BR-CXL-08: Cash bookings collect nothing online and issue no refund or fee.
        if ($payment && $payment->payment_method === PaymentMethod::Cash) {
            return new RefundCalculation(
                eligible: false,
                requiresRefundRecord: false,
                policyType: 'cash',
                basis: RefundBasis::CashUncollected,
                refundPercentage: 0,
                lessonPrice: $lessonPrice,
                serviceFeeRetained: 0.0,
                refundAmount: 0.0,
                instructorRetainedAmount: 0.0,
                daysBeforeSession: $this->daysBeforeSession($booking, $cancelDay, $tz),
                daysSincePayment: null,
                canAutoDispatch: false,
                dispatchMode: 'none',
                summary: 'Cash booking cancelled — no amount was collected online and no refund applies.',
            );
        }

        $hasPaidDigital = $payment !== null && in_array($payment->status, [
            PaymentStatus::Completed,
            PaymentStatus::RefundRequested,
            PaymentStatus::RefundProcessing,
            PaymentStatus::Refunded,
        ], true);

        // BR-CXL-01: Withdrawing an unpaid pending / awaiting-payment request incurs no charge.
        if (! $hasPaidDigital) {
            return new RefundCalculation(
                eligible: false,
                requiresRefundRecord: false,
                policyType: 'unpaid',
                basis: RefundBasis::PendingUnpaid,
                refundPercentage: 0,
                lessonPrice: $lessonPrice,
                serviceFeeRetained: 0.0,
                refundAmount: 0.0,
                instructorRetainedAmount: 0.0,
                daysBeforeSession: $this->daysBeforeSession($booking, $cancelDay, $tz),
                daysSincePayment: null,
                canAutoDispatch: false,
                dispatchMode: 'none',
                summary: 'Unpaid booking request — cancelling releases your slot with no charge.',
            );
        }

        $daysBeforeSession = $this->daysBeforeSession($booking, $cancelDay, $tz);
        $daysSincePayment = $this->daysSincePayment($booking, $payment, $cancelDay, $tz);

        // BR-CXL-09: Student no-show without cancelling yields no refund; lesson price goes to instructor.
        if ($reason === CancellationReason::StudentNoShow) {
            return new RefundCalculation(
                eligible: false,
                requiresRefundRecord: false,
                policyType: 'no_show',
                basis: RefundBasis::StudentNoShow,
                refundPercentage: 0,
                lessonPrice: $lessonPrice,
                serviceFeeRetained: $serviceFee,
                refundAmount: 0.0,
                instructorRetainedAmount: $lessonPrice,
                daysBeforeSession: $daysBeforeSession,
                daysSincePayment: $daysSincePayment,
                canAutoDispatch: false,
                dispatchMode: 'none',
                summary: 'Student did not attend — no refund applies and the lesson price is credited to the instructor.',
            );
        }

        // Instructor-side cancellation, unsafe weather/sea conditions, suspension,
        // or student declining an instructor-initiated reschedule -> always 100% of lesson price.
        $isRescheduleDeclined = $reason === CancellationReason::RescheduleDeclined
            || ($reason === CancellationReason::Student && $booking->hasPendingRescheduleResponse());

        if ($isRescheduleDeclined || $reason->isRefundable()) {
            $basis = match (true) {
                $isRescheduleDeclined => RefundBasis::InstructorRescheduleDeclined,
                $reason === CancellationReason::Weather => RefundBasis::WeatherSafety,
                $reason === CancellationReason::InstructorNoShow => RefundBasis::InstructorNoShow,
                $reason === CancellationReason::InstructorSuspended => RefundBasis::InstructorSuspended,
                $reason === CancellationReason::Admin => RefundBasis::AdminOverride,
                default => RefundBasis::InstructorCancelled,
            };

            $dispatchMode = $this->resolveDispatchMode($payment, $daysSincePayment);

            return new RefundCalculation(
                eligible: $lessonPrice > 0,
                requiresRefundRecord: $lessonPrice > 0,
                policyType: $reason === CancellationReason::Admin ? 'admin_override' : 'instructor_fault',
                basis: $basis,
                refundPercentage: 100,
                lessonPrice: $lessonPrice,
                serviceFeeRetained: $serviceFee,
                refundAmount: $lessonPrice,
                instructorRetainedAmount: 0.0,
                daysBeforeSession: $daysBeforeSession,
                daysSincePayment: $daysSincePayment,
                canAutoDispatch: in_array($dispatchMode, ['paymongo', 'xendit', 'mock'], true),
                dispatchMode: $dispatchMode,
                summary: $this->formatSummary(100, $lessonPrice, $serviceFee, $dispatchMode, $basis),
            );
        }

        // Student-initiated tiered policy on a paid GCash booking.
        $isLongTerm = $this->isLongTermBooking($booking, $cancelMoment, $tz);
        $longTermWindow = (int) config('siasurf.refunds.long_term_window_days', 30);
        $fullDays = (int) config('siasurf.refunds.short_term_full_days', 7);
        $halfDays = (int) config('siasurf.refunds.short_term_half_days', 3);
        $halfPercent = (int) config('siasurf.refunds.short_term_half_percent', 50);

        if ($isLongTerm) {
            $policyType = 'long_term';

            if ($daysSincePayment <= $longTermWindow) {
                $percentage = 100;
                $basis = RefundBasis::LongTermWithin30Days;
            } elseif ($daysBeforeSession >= $fullDays) {
                // Past 30-day payment window: fall through to short-term sliding window.
                $percentage = 100;
                $basis = RefundBasis::LongTermFallbackFull;
            } elseif ($daysBeforeSession >= $halfDays) {
                $percentage = $halfPercent;
                $basis = RefundBasis::LongTermFallbackHalf;
            } else {
                $percentage = 0;
                $basis = RefundBasis::LongTermFallbackNoRefund;
            }
        } else {
            $policyType = 'short_term';

            if ($daysBeforeSession >= $fullDays) {
                $percentage = 100;
                $basis = RefundBasis::ShortTermFull;
            } elseif ($daysBeforeSession >= $halfDays) {
                $percentage = $halfPercent;
                $basis = RefundBasis::ShortTermHalf;
            } else {
                $percentage = 0;
                $basis = RefundBasis::ShortTermNoRefund;
            }
        }

        $refundAmount = round($lessonPrice * ($percentage / 100), 2);
        $instructorRetained = round(max(0.0, $lessonPrice - $refundAmount), 2);
        $requiresRefundRecord = $refundAmount > 0;
        $dispatchMode = $requiresRefundRecord
            ? $this->resolveDispatchMode($payment, $daysSincePayment)
            : 'none';

        return new RefundCalculation(
            eligible: $refundAmount > 0,
            requiresRefundRecord: $requiresRefundRecord,
            policyType: $policyType,
            basis: $basis,
            refundPercentage: $percentage,
            lessonPrice: $lessonPrice,
            serviceFeeRetained: $serviceFee,
            refundAmount: $refundAmount,
            instructorRetainedAmount: $instructorRetained,
            daysBeforeSession: $daysBeforeSession,
            daysSincePayment: $daysSincePayment,
            canAutoDispatch: in_array($dispatchMode, ['paymongo', 'xendit', 'mock'], true),
            dispatchMode: $dispatchMode,
            summary: $this->formatSummary($percentage, $refundAmount, $serviceFee, $dispatchMode, $basis),
        );
    }

    /**
     * Build a UI-friendly preview for the student's cancellation modal.
     *
     * @return array<string, mixed>
     */
    public function previewForStudent(Booking $booking): array
    {
        $reason = $booking->hasPendingRescheduleResponse()
            ? CancellationReason::RescheduleDeclined
            : CancellationReason::Student;

        return $this->calculate($booking, $reason)->toArray();
    }

    public function isLongTermBooking(Booking $booking, ?Carbon $fallbackMoment = null, ?string $tz = null): bool
    {
        if ($booking->is_long_term !== null) {
            return (bool) $booking->is_long_term;
        }

        $tz = $tz ?? config('app.timezone', 'Asia/Manila');
        $bookedDay = ($booking->created_at ?? $fallbackMoment ?? now())
            ->copy()
            ->timezone($tz)
            ->startOfDay();
        $originalSessionDay = ($booking->effectiveOriginalDate() ?? $booking->date)
            ->copy()
            ->timezone($tz)
            ->startOfDay();

        $leadDays = (int) round($bookedDay->diffInDays($originalSessionDay, false));
        $threshold = (int) config('siasurf.refunds.long_term_threshold_days', 30);

        return $leadDays > $threshold;
    }

    private function daysBeforeSession(Booking $booking, Carbon $cancelDay, string $tz): int
    {
        $sessionDay = ($booking->date ?? $cancelDay)
            ->copy()
            ->timezone($tz)
            ->startOfDay();

        return (int) round($cancelDay->diffInDays($sessionDay, false));
    }

    private function daysSincePayment(Booking $booking, Payment $payment, Carbon $cancelDay, string $tz): int
    {
        $paidAt = ($payment->paid_at ?? $payment->receipt_verified_at ?? $booking->created_at ?? $cancelDay)
            ->copy()
            ->timezone($tz)
            ->startOfDay();

        return max(0, (int) round($paidAt->diffInDays($cancelDay, false)));
    }

    private function resolveDispatchMode(Payment $payment, int $daysSincePayment): string
    {
        $maxDays = (int) config('siasurf.refunds.max_days_from_payment', 30);

        if ($daysSincePayment > $maxDays) {
            return 'manual_required';
        }

        $gatewayId = $payment->gateway_payment_id;
        $transactionId = $payment->transaction_id;

        if (! empty($gatewayId)) {
            if (str_starts_with((string) $gatewayId, 'mock_')) {
                return 'mock';
            }

            if (str_starts_with((string) $gatewayId, 'ewc_')) {
                return 'xendit';
            }

            return 'paymongo';
        }

        // Receipt-verified manual GCash transfers without a gateway payment ID
        // must always complete manually rather than calling gateway/mock APIs.
        if ($payment->hasReceipt()) {
            return 'manual_required';
        }

        if (! empty($transactionId)) {
            if (str_starts_with((string) $transactionId, 'mock_')) {
                return 'mock';
            }

            if (str_starts_with((string) $transactionId, 'ewc_')) {
                return 'xendit';
            }
        }

        // Fallback for manual GCash transfers with no gateway payment ID.
        return 'manual_required';
    }

    private function formatSummary(
        int $percentage,
        float $refundAmount,
        float $serviceFee,
        string $dispatchMode,
        RefundBasis $basis
    ): string {
        if ($percentage <= 0 || $refundAmount <= 0) {
            $feeText = $serviceFee > 0
                ? ' The ₱' . number_format($serviceFee, 2) . ' service fee is non-refundable.'
                : '';

            return $basis->description() . $feeText;
        }

        $amountText = '₱' . number_format($refundAmount, 2);
        $feeNote = $serviceFee > 0
            ? ' The ₱' . number_format($serviceFee, 2) . ' platform service fee is non-refundable.'
            : '';

        if ($dispatchMode === 'manual_required') {
            return "Eligible for a {$percentage}% refund ({$amountText}). SISA Admin will process this refund manually." . $feeNote;
        }

        return "Eligible for a {$percentage}% refund ({$amountText}), dispatched automatically via GCash/PayMongo." . $feeNote;
    }
}
