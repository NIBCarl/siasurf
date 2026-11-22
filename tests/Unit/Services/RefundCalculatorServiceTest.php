<?php

namespace Tests\Unit\Services;

use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundBasis;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\RefundCalculatorService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RefundCalculatorServiceTest extends TestCase
{
    private RefundCalculatorService $calculator;

    private Carbon $cancelNow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new RefundCalculatorService();
        $tz = config('app.timezone', 'Asia/Manila');
        $this->cancelNow = Carbon::create(2026, 11, 22, 10, 0, 0, $tz);
        Carbon::setTestNow($this->cancelNow);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makePaidGcashBooking(
        int $leadDaysFromBooking,
        int $daysBeforeSession,
        int $daysSincePayment,
        ?bool $isLongTerm = null,
        string $transactionId = 'mock_cs_test_123',
        ?string $gatewayPaymentId = 'mock_pay_123',
        float $lessonPrice = 1200.00,
        float $serviceFee = 84.00,
    ): Booking {
        $paidAt = $this->cancelNow->copy()->subDays($daysSincePayment);
        $bookedAt = $paidAt->copy();
        $sessionDay = $this->cancelNow->copy()->startOfDay()->addDays($daysBeforeSession);
        $originalDay = $bookedAt->copy()->startOfDay()->addDays($leadDaysFromBooking);

        $booking = new Booking([
            'date' => $sessionDay->toDateString(),
            'original_date' => $originalDay->toDateString(),
            'is_long_term' => $isLongTerm,
            'lesson_price' => $lessonPrice,
            'service_fee_percent' => 7.00,
            'service_fee_amount' => $serviceFee,
            'total_amount' => $lessonPrice + $serviceFee,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Completed,
        ]);
        $booking->created_at = $bookedAt;

        $payment = new Payment([
            'amount' => $lessonPrice + $serviceFee,
            'lesson_price' => $lessonPrice,
            'service_fee_amount' => $serviceFee,
            'payment_method' => PaymentMethod::GCash,
            'status' => PaymentStatus::Completed,
            'transaction_id' => $transactionId,
            'gateway_payment_id' => $gatewayPaymentId,
            'paid_at' => $paidAt,
        ]);

        $booking->setRelation('payment', $payment);

        return $booking;
    }

    #[Test]
    public function r1_long_term_booking_within_30_days_of_payment_gets_100_percent_lesson_price_and_retains_7_percent_fee(): void
    {
        // Booked 45 days ahead, cancelled 10 days after payment (35 days before session)
        $booking = $this->makePaidGcashBooking(
            leadDaysFromBooking: 45,
            daysBeforeSession: 35,
            daysSincePayment: 10,
        );

        $calc = $this->calculator->calculate($booking, CancellationReason::Student, $this->cancelNow);

        $this->assertTrue($calc->eligible);
        $this->assertTrue($calc->requiresRefundRecord);
        $this->assertSame('long_term', $calc->policyType);
        $this->assertSame(RefundBasis::LongTermWithin30Days, $calc->basis);
        $this->assertSame(100, $calc->refundPercentage);
        $this->assertSame(1200.00, $calc->refundAmount);
        $this->assertSame(84.00, $calc->serviceFeeRetained);
        $this->assertSame(0.00, $calc->instructorRetainedAmount);
        $this->assertTrue($calc->canAutoDispatch);
        $this->assertSame('mock', $calc->dispatchMode);
    }

    #[Test]
    public function r1_boundary_exactly_30_days_since_payment_on_long_term_still_gets_100_percent(): void
    {
        $booking = $this->makePaidGcashBooking(
            leadDaysFromBooking: 45,
            daysBeforeSession: 15,
            daysSincePayment: 30,
        );

        $calc = $this->calculator->calculate($booking, CancellationReason::Student, $this->cancelNow);

        $this->assertSame(RefundBasis::LongTermWithin30Days, $calc->basis);
        $this->assertSame(100, $calc->refundPercentage);
        $this->assertSame(1200.00, $calc->refundAmount);
        $this->assertTrue($calc->canAutoDispatch);
    }

    #[Test]
    public function r2_long_term_past_30_days_falls_through_to_short_term_sliding_window_with_manual_review(): void
    {
        // Day 31 from payment with 10 days before session -> falls through to 7+ days = 100%, manual_required
        $fullFallThrough = $this->makePaidGcashBooking(
            leadDaysFromBooking: 45,
            daysBeforeSession: 10,
            daysSincePayment: 31,
            transactionId: 'cs_paymongo_1',
            gatewayPaymentId: 'pay_live_1',
        );
        $fullCalc = $this->calculator->calculate($fullFallThrough, CancellationReason::Student, $this->cancelNow);

        $this->assertTrue($fullCalc->eligible);
        $this->assertSame('long_term', $fullCalc->policyType);
        $this->assertSame(RefundBasis::LongTermFallbackFull, $fullCalc->basis);
        $this->assertSame(100, $fullCalc->refundPercentage);
        $this->assertSame(1200.00, $fullCalc->refundAmount);
        $this->assertSame(84.00, $fullCalc->serviceFeeRetained);
        $this->assertFalse($fullCalc->canAutoDispatch);
        $this->assertSame('manual_required', $fullCalc->dispatchMode);

        // Day 36 from payment with 5 days left -> falls through to 3-6 days = 50%, manual_required
        $halfFallThrough = $this->makePaidGcashBooking(
            leadDaysFromBooking: 45,
            daysBeforeSession: 5,
            daysSincePayment: 36,
            transactionId: 'cs_paymongo_2',
            gatewayPaymentId: 'pay_live_2',
        );
        $halfCalc = $this->calculator->calculate($halfFallThrough, CancellationReason::Student, $this->cancelNow);

        $this->assertTrue($halfCalc->eligible);
        $this->assertSame(RefundBasis::LongTermFallbackHalf, $halfCalc->basis);
        $this->assertSame(50, $halfCalc->refundPercentage);
        $this->assertSame(600.00, $halfCalc->refundAmount);
        $this->assertSame(600.00, $halfCalc->instructorRetainedAmount);
        $this->assertSame(84.00, $halfCalc->serviceFeeRetained);
        $this->assertSame('manual_required', $halfCalc->dispatchMode);

        // Day 40 from payment with 2 days left -> falls through to < 3 days = 0%
        $zeroFallThrough = $this->makePaidGcashBooking(
            leadDaysFromBooking: 45,
            daysBeforeSession: 2,
            daysSincePayment: 40,
        );
        $zeroCalc = $this->calculator->calculate($zeroFallThrough, CancellationReason::Student, $this->cancelNow);

        $this->assertFalse($zeroCalc->eligible);
        $this->assertFalse($zeroCalc->requiresRefundRecord);
        $this->assertSame(RefundBasis::LongTermFallbackNoRefund, $zeroCalc->basis);
        $this->assertSame(0, $zeroCalc->refundPercentage);
        $this->assertSame(0.00, $zeroCalc->refundAmount);
        $this->assertSame(1200.00, $zeroCalc->instructorRetainedAmount);
        $this->assertSame(84.00, $zeroCalc->serviceFeeRetained);
        $this->assertSame('none', $zeroCalc->dispatchMode);
    }

    #[Test]
    public function r6_boundary_exactly_30_days_ahead_at_booking_is_classified_as_short_term(): void
    {
        // Booked 30 days ahead, cancelled 5 days before session (25 days after payment)
        // If treated as long-term within 30d it would give 100%, but exactly 30 days ahead is short-term -> 50%!
        $booking = $this->makePaidGcashBooking(
            leadDaysFromBooking: 30,
            daysBeforeSession: 5,
            daysSincePayment: 25,
        );

        $this->assertFalse($this->calculator->isLongTermBooking($booking, $this->cancelNow));

        $calc = $this->calculator->calculate($booking, CancellationReason::Student, $this->cancelNow);

        $this->assertSame('short_term', $calc->policyType);
        $this->assertSame(RefundBasis::ShortTermHalf, $calc->basis);
        $this->assertSame(50, $calc->refundPercentage);
        $this->assertSame(600.00, $calc->refundAmount);
    }

    #[Test]
    public function r3_r4_r5_r7_short_term_sliding_window_and_boundaries(): void
    {
        // 7+ days away (including exact boundary 7) -> 100%
        foreach ([14, 7] as $days) {
            $booking = $this->makePaidGcashBooking(leadDaysFromBooking: 20, daysBeforeSession: $days, daysSincePayment: 2);
            $calc = $this->calculator->calculate($booking, CancellationReason::Student, $this->cancelNow);

            $this->assertSame(RefundBasis::ShortTermFull, $calc->basis);
            $this->assertSame(100, $calc->refundPercentage);
            $this->assertSame(1200.00, $calc->refundAmount);
            $this->assertSame(0.00, $calc->instructorRetainedAmount);
            $this->assertSame(84.00, $calc->serviceFeeRetained);
        }

        // 3 to 6 days away (boundaries 6 and 3) -> 50%
        foreach ([6, 3] as $days) {
            $booking = $this->makePaidGcashBooking(leadDaysFromBooking: 14, daysBeforeSession: $days, daysSincePayment: 2);
            $calc = $this->calculator->calculate($booking, CancellationReason::Student, $this->cancelNow);

            $this->assertSame(RefundBasis::ShortTermHalf, $calc->basis);
            $this->assertSame(50, $calc->refundPercentage);
            $this->assertSame(600.00, $calc->refundAmount);
            $this->assertSame(600.00, $calc->instructorRetainedAmount);
            $this->assertSame(84.00, $calc->serviceFeeRetained);
        }

        // Under 3 days / same day (2, 1, 0) -> 0%
        foreach ([2, 1, 0] as $days) {
            $booking = $this->makePaidGcashBooking(leadDaysFromBooking: 10, daysBeforeSession: $days, daysSincePayment: 2);
            $calc = $this->calculator->calculate($booking, CancellationReason::Student, $this->cancelNow);

            $this->assertFalse($calc->eligible);
            $this->assertFalse($calc->requiresRefundRecord);
            $this->assertSame(RefundBasis::ShortTermNoRefund, $calc->basis);
            $this->assertSame(0, $calc->refundPercentage);
            $this->assertSame(0.00, $calc->refundAmount);
            $this->assertSame(1200.00, $calc->instructorRetainedAmount);
            $this->assertSame(84.00, $calc->serviceFeeRetained);
        }
    }

    #[Test]
    public function r10_cash_booking_cancelled_yields_zero_refund_and_zero_fee(): void
    {
        $booking = new Booking([
            'date' => $this->cancelNow->copy()->addDays(1)->toDateString(),
            'lesson_price' => 1200.00,
            'service_fee_percent' => 7.00,
            'service_fee_amount' => 84.00,
            'total_amount' => 1284.00,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $payment = new Payment([
            'amount' => 1284.00,
            'payment_method' => PaymentMethod::Cash,
            'status' => PaymentStatus::Pending,
        ]);
        $booking->setRelation('payment', $payment);

        $calc = $this->calculator->calculate($booking, CancellationReason::Student, $this->cancelNow);

        $this->assertFalse($calc->eligible);
        $this->assertFalse($calc->requiresRefundRecord);
        $this->assertSame(RefundBasis::CashUncollected, $calc->basis);
        $this->assertSame(0.00, $calc->refundAmount);
        $this->assertSame(0.00, $calc->serviceFeeRetained);
        $this->assertSame(0.00, $calc->instructorRetainedAmount);
    }

    #[Test]
    public function unpaid_pending_withdrawal_yields_zero_refund_and_zero_fee(): void
    {
        $booking = new Booking([
            'date' => $this->cancelNow->copy()->addDays(2)->toDateString(),
            'lesson_price' => 1200.00,
            'service_fee_percent' => 7.00,
            'service_fee_amount' => 84.00,
            'total_amount' => 1284.00,
            'status' => BookingStatus::Pending,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $calc = $this->calculator->calculate($booking, CancellationReason::Student, $this->cancelNow);

        $this->assertFalse($calc->eligible);
        $this->assertFalse($calc->requiresRefundRecord);
        $this->assertSame(RefundBasis::PendingUnpaid, $calc->basis);
        $this->assertSame(0.00, $calc->refundAmount);
        $this->assertSame(0.00, $calc->serviceFeeRetained);
    }

    #[Test]
    public function r11_instructor_weather_noshow_and_suspended_cancellations_always_refund_100_percent_lesson_price(): void
    {
        $cases = [
            [CancellationReason::Instructor, RefundBasis::InstructorCancelled],
            [CancellationReason::Weather, RefundBasis::WeatherSafety],
            [CancellationReason::InstructorNoShow, RefundBasis::InstructorNoShow],
            [CancellationReason::InstructorSuspended, RefundBasis::InstructorSuspended],
        ];

        foreach ($cases as [$reason, $expectedBasis]) {
            // Even same day (< 3d) refunds 100% of lesson price
            $booking = $this->makePaidGcashBooking(leadDaysFromBooking: 5, daysBeforeSession: 0, daysSincePayment: 3);
            $calc = $this->calculator->calculate($booking, $reason, $this->cancelNow);

            $this->assertTrue($calc->eligible);
            $this->assertTrue($calc->requiresRefundRecord);
            $this->assertSame($expectedBasis, $calc->basis);
            $this->assertSame(100, $calc->refundPercentage);
            $this->assertSame(1200.00, $calc->refundAmount);
            $this->assertSame(84.00, $calc->serviceFeeRetained);
            $this->assertSame(0.00, $calc->instructorRetainedAmount);
        }
    }

    #[Test]
    public function instructor_reschedule_gives_100_percent_until_accepted_then_uses_original_classification_and_new_date(): void
    {
        // Originally booked 45 days ahead (snapshotted is_long_term = true), rescheduled to 5 days ahead
        $booking = $this->makePaidGcashBooking(
            leadDaysFromBooking: 45,
            daysBeforeSession: 5,
            daysSincePayment: 36,
            isLongTerm: true,
        );
        $booking->rescheduled_by_instructor_at = $this->cancelNow->copy()->subDay();
        $booking->student_accepted_reschedule_at = null;

        // Pending acceptance -> student declining gets full 100% lesson price!
        $declinedCalc = $this->calculator->calculate($booking, CancellationReason::Student, $this->cancelNow);
        $this->assertSame(RefundBasis::InstructorRescheduleDeclined, $declinedCalc->basis);
        $this->assertSame(100, $declinedCalc->refundPercentage);
        $this->assertSame(1200.00, $declinedCalc->refundAmount);
        $this->assertSame(0.00, $declinedCalc->instructorRetainedAmount);

        // After student accepts the rescheduled date -> uses snapshotted is_long_term (true) + fall-through via current date (5 days left -> 50%)
        $booking->student_accepted_reschedule_at = $this->cancelNow->copy();
        $acceptedCalc = $this->calculator->calculate($booking, CancellationReason::Student, $this->cancelNow);
        $this->assertSame('long_term', $acceptedCalc->policyType);
        $this->assertSame(RefundBasis::LongTermFallbackHalf, $acceptedCalc->basis);
        $this->assertSame(50, $acceptedCalc->refundPercentage);
        $this->assertSame(600.00, $acceptedCalc->refundAmount);
    }

    #[Test]
    public function manual_verified_gcash_without_gateway_payment_id_uses_manual_required_dispatch(): void
    {
        $booking = $this->makePaidGcashBooking(
            leadDaysFromBooking: 14,
            daysBeforeSession: 10,
            daysSincePayment: 2,
            transactionId: 'GCASH-REF-001',
            gatewayPaymentId: null,
        );

        $calc = $this->calculator->calculate($booking, CancellationReason::Student, $this->cancelNow);

        $this->assertTrue($calc->eligible);
        $this->assertSame(100, $calc->refundPercentage);
        $this->assertFalse($calc->canAutoDispatch);
        $this->assertSame('manual_required', $calc->dispatchMode);
    }

    #[Test]
    public function r12_student_no_show_issues_no_refund_and_credits_lesson_price_to_instructor(): void
    {
        $booking = $this->makePaidGcashBooking(leadDaysFromBooking: 10, daysBeforeSession: 0, daysSincePayment: 5);

        $calc = $this->calculator->calculate($booking, CancellationReason::StudentNoShow, $this->cancelNow);

        $this->assertFalse($calc->eligible);
        $this->assertFalse($calc->requiresRefundRecord);
        $this->assertSame(RefundBasis::StudentNoShow, $calc->basis);
        $this->assertSame(0.00, $calc->refundAmount);
        $this->assertSame(84.00, $calc->serviceFeeRetained);
        $this->assertSame(1200.00, $calc->instructorRetainedAmount);
    }
}
