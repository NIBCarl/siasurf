<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\ChangeRequestStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundBasis;
use App\Enums\RefundStatus;
use App\Enums\TimePeriod;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\InstructorProfile;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\SurfSpot;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\BookingNoShowNotification;
use App\Notifications\RefundStatusNotification;
use App\Services\SuspensionService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CancellationAndRefundTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();

        $tz = config('app.timezone', 'Asia/Manila');
        $this->now = Carbon::create(2026, 11, 22, 10, 0, 0, $tz);
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{student: User, instructor: User, admin: User, spot: SurfSpot, profile: InstructorProfile}
     */
    private function createActors(): array
    {
        $student = User::factory()->student()->create();
        $student->assignRole('student');

        $instructor = User::factory()->instructor()->create();
        $instructor->assignRole('instructor');

        $admin = User::factory()->admin()->create();
        $admin->assignRole('admin');

        $spot = SurfSpot::factory()->create();
        $profile = InstructorProfile::factory()->level1()->for($instructor, 'user')->create();

        return compact('student', 'instructor', 'admin', 'spot', 'profile');
    }

    private function createPaidGcashBooking(
        array $actors,
        int $daysUntilSession = 10,
        int $daysSincePayment = 2,
        bool $isLongTerm = false,
        ?string $gatewayPaymentId = 'mock_pay_abc123',
        string $transactionId = 'mock_cs_abc123',
        float $lessonPrice = 1200.00,
    ): array {
        $paidAt = $this->now->copy()->subDays($daysSincePayment);
        $sessionDate = $this->now->copy()->startOfDay()->addDays($daysUntilSession)->toDateString();
        $serviceFee = round($lessonPrice * 0.07, 2);

        $booking = Booking::factory()->withServiceFee($lessonPrice)->confirmed()->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
            'date' => $sessionDate,
            'original_date' => $sessionDate,
            'is_long_term' => $isLongTerm,
            'time_period' => TimePeriod::Morning->value,
            'payment_status' => PaymentStatus::Completed->value,
            'created_at' => $paidAt,
        ]);

        $payment = Payment::factory()->gcash()->completed()->create([
            'booking_id' => $booking->id,
            'amount' => $lessonPrice + $serviceFee,
            'lesson_price' => $lessonPrice,
            'service_fee_amount' => $serviceFee,
            'transaction_id' => $transactionId,
            'gateway_payment_id' => $gatewayPaymentId,
            'paid_at' => $paidAt,
            'receipt_verified_at' => $gatewayPaymentId === null ? $paidAt : null,
        ]);

        return [$booking->fresh(['payment']), $payment];
    }

    #[Test]
    public function paid_gcash_student_self_cancellation_issues_refund_dispatches_mock_gateway_releases_slot_and_notifies(): void
    {
        $actors = $this->createActors();
        [$booking, $payment] = $this->createPaidGcashBooking($actors, daysUntilSession: 10, daysSincePayment: 2);

        $availability = Availability::create([
            'user_id' => $actors['instructor']->id,
            'day_of_week' => $booking->date->dayOfWeek,
            'specific_date' => $booking->date->toDateString(),
            'time_period' => TimePeriod::Morning->value,
            'start_time' => '07:00',
            'end_time' => '09:00',
            'is_available' => false,
            'blocked_by_booking_id' => $booking->id,
        ]);

        $this->assertTrue($booking->canBeCancelled());

        $response = $this->actingAs($actors['student'])
            ->post(route('student.bookings.cancel', $booking), [
                'reason' => 'Flight got rescheduled',
            ]);

        $response->assertRedirect();

        $booking->refresh();
        $payment->refresh();

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(CancellationReason::Student, $booking->cancellation_reason);
        $this->assertSame('1200.00', $booking->refund_amount);
        $this->assertSame('0.00', $booking->instructor_retained_amount);
        $this->assertSame(PaymentStatus::Refunded, $booking->payment_status);
        $this->assertSame(PaymentStatus::Refunded, $payment->status);

        $refund = Refund::where('booking_id', $booking->id)->first();
        $this->assertNotNull($refund);
        $this->assertSame(RefundBasis::ShortTermFull, $refund->basis);
        $this->assertSame('1200.00', $refund->amount);
        $this->assertSame('84.00', $refund->service_fee_retained);
        $this->assertSame(100, $refund->refund_percentage);
        $this->assertSame(RefundStatus::Completed, $refund->status);

        // System-generated slot lock removed cleanly
        $this->assertDatabaseMissing('availabilities', ['id' => $availability->id]);

        Notification::assertSentTo($actors['student'], BookingCancelledNotification::class);
        Notification::assertSentTo($actors['instructor'], BookingCancelledNotification::class);
        Notification::assertSentTo($actors['student'], RefundStatusNotification::class);
    }

    #[Test]
    public function r14_and_r10_unpaid_pending_withdrawal_and_cash_cancellation_create_no_refund_records(): void
    {
        $actors = $this->createActors();

        // R14: Unpaid pending request
        $pending = Booking::factory()->withServiceFee(1200.00)->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
            'date' => $this->now->copy()->addDays(4)->toDateString(),
            'status' => BookingStatus::Pending->value,
            'payment_status' => PaymentStatus::Pending->value,
        ]);

        $this->actingAs($actors['student'])
            ->post(route('student.bookings.cancel', $pending), ['reason' => 'Withdrawing request'])
            ->assertRedirect();

        $pending->refresh();
        $this->assertSame(BookingStatus::Cancelled, $pending->status);
        $this->assertSame('0.00', $pending->refund_amount);
        $this->assertSame(0, Refund::where('booking_id', $pending->id)->count());

        // R10: Cash booking cancelled
        $cashBooking = Booking::factory()->withServiceFee(1200.00)->confirmed()->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
            'date' => $this->now->copy()->addDays(1)->toDateString(),
            'payment_status' => PaymentStatus::Pending->value,
        ]);
        Payment::factory()->cash()->create([
            'booking_id' => $cashBooking->id,
            'amount' => 1284.00,
            'lesson_price' => 1200.00,
            'service_fee_amount' => 84.00,
            'status' => PaymentStatus::Pending->value,
        ]);

        $this->actingAs($actors['student'])
            ->post(route('student.bookings.cancel', $cashBooking), ['reason' => 'Plans changed'])
            ->assertRedirect();

        $cashBooking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $cashBooking->status);
        $this->assertSame('0.00', $cashBooking->refund_amount);
        $this->assertSame('0.00', $cashBooking->instructor_retained_amount);
        $this->assertSame(0, Refund::where('booking_id', $cashBooking->id)->count());
    }

    #[Test]
    public function unverified_gcash_receipt_blocks_cancellation_until_verified_then_routes_to_manual_admin_completion(): void
    {
        $actors = $this->createActors();

        $booking = Booking::factory()->withServiceFee(1200.00)->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
            'date' => $this->now->copy()->addDays(10)->toDateString(),
            'original_date' => $this->now->copy()->addDays(10)->toDateString(),
            'is_long_term' => false,
            'status' => BookingStatus::AwaitingPayment->value,
            'payment_status' => PaymentStatus::PendingVerification->value,
        ]);

        $payment = Payment::factory()->gcash()->create([
            'booking_id' => $booking->id,
            'amount' => 1284.00,
            'lesson_price' => 1200.00,
            'service_fee_amount' => 84.00,
            'status' => PaymentStatus::PendingVerification->value,
            'transaction_id' => 'GCASH-MANUAL-001',
            'gateway_payment_id' => null,
            'receipt_path' => 'receipts/test.jpg',
            'receipt_uploaded_at' => $this->now->copy()->subHour(),
        ]);

        $booking->refresh();
        $this->assertFalse($booking->canBeCancelled());

        $this->actingAs($actors['student'])
            ->post(route('student.bookings.cancel', $booking), ['reason' => 'Trying to cancel'])
            ->assertForbidden();

        $this->assertSame(BookingStatus::AwaitingPayment, $booking->fresh()->status);

        // Now receipt is verified by instructor/admin -> payment Completed (manual GCash, no gateway ID)
        $payment->update([
            'status' => PaymentStatus::Completed->value,
            'receipt_verified_at' => $this->now->copy(),
            'paid_at' => $this->now->copy(),
        ]);
        $booking->update([
            'status' => BookingStatus::Confirmed->value,
            'payment_status' => PaymentStatus::Completed->value,
        ]);

        $this->assertTrue($booking->fresh()->canBeCancelled());

        $this->actingAs($actors['student'])
            ->post(route('student.bookings.cancel', $booking), ['reason' => 'Need to cancel'])
            ->assertRedirect();

        $refund = Refund::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(RefundStatus::ManualRequired, $refund->status);
        $this->assertSame('manual_gcash', $refund->gateway);
        $this->assertSame('1200.00', $refund->amount);
        $this->assertSame(PaymentStatus::RefundRequested, $booking->fresh()->payment_status);

        // Admin marks manual GCash refund completed
        $this->actingAs($actors['admin'])
            ->post(route('admin.refunds.complete-manual', $refund), [
                'reference' => 'GCASH-PAYOUT-999',
                'note' => 'Transferred to student GCash number',
            ])
            ->assertRedirect();

        $refund->refresh();
        $payment->refresh();
        $booking->refresh();

        $this->assertSame(RefundStatus::Completed, $refund->status);
        $this->assertSame('GCASH-PAYOUT-999', $refund->paymongo_refund_id);
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(PaymentStatus::Refunded, $booking->payment_status);
        Notification::assertSentTo($actors['student'], RefundStatusNotification::class);
    }

    #[Test]
    public function r11_instructor_weather_and_suspension_cancellations_refund_100_percent_lesson_price(): void
    {
        $actors = $this->createActors();

        // >= 24h away: auto-approved instructor weather cancellation -> 100% refund
        [$weatherBooking] = $this->createPaidGcashBooking($actors, daysUntilSession: 2, daysSincePayment: 1);

        $this->actingAs($actors['instructor'])
            ->post(route('instructor.bookings.change-request', $weatherBooking), [
                'type' => 'cancellation',
                'reason' => 'Unsafe typhoon swell at Cloud 9',
                'cancellation_reason' => 'weather',
            ])
            ->assertRedirect();

        $weatherBooking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $weatherBooking->status);
        $this->assertSame(CancellationReason::Weather, $weatherBooking->cancellation_reason);

        $weatherRefund = Refund::where('booking_id', $weatherBooking->id)->firstOrFail();
        $this->assertSame(RefundBasis::WeatherSafety, $weatherRefund->basis);
        $this->assertSame('1200.00', $weatherRefund->amount);
        $this->assertSame('84.00', $weatherRefund->service_fee_retained);
        $this->assertSame(RefundStatus::Completed, $weatherRefund->status);

        // < 24h away: creates pending change request -> admin approves -> 100% refund
        [$urgentBooking] = $this->createPaidGcashBooking($actors, daysUntilSession: 0, daysSincePayment: 1);
        $urgentBooking->update([
            'time_period' => TimePeriod::Afternoon->value,
            'start_time' => '14:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->actingAs($actors['instructor'])
            ->post(route('instructor.bookings.change-request', $urgentBooking), [
                'type' => 'cancellation',
                'reason' => 'Family emergency',
                'cancellation_reason' => 'instructor',
            ])
            ->assertRedirect();

        $changeRequest = BookingChangeRequest::where('booking_id', $urgentBooking->id)->firstOrFail();
        $this->assertSame(ChangeRequestStatus::Pending, $changeRequest->status);

        $this->actingAs($actors['admin'])
            ->post(route('admin.change-requests.approve', $changeRequest), [
                'admin_note' => 'Approved emergency cancellation',
            ])
            ->assertRedirect();

        $urgentBooking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $urgentBooking->status);
        $this->assertSame(CancellationReason::Instructor, $urgentBooking->cancellation_reason);
        $urgentRefund = Refund::where('booking_id', $urgentBooking->id)->firstOrFail();
        $this->assertSame(RefundBasis::InstructorCancelled, $urgentRefund->basis);
        $this->assertSame('1200.00', $urgentRefund->amount);

        // SuspensionService::suspendManually cancels future bookings with 100% refund
        [$suspendedBooking] = $this->createPaidGcashBooking($actors, daysUntilSession: 12, daysSincePayment: 3);

        app(SuspensionService::class)->suspendManually(
            $actors['instructor'],
            'Policy violation suspension',
            30,
            $actors['admin']
        );

        $suspendedBooking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $suspendedBooking->status);
        $this->assertSame(CancellationReason::InstructorSuspended, $suspendedBooking->cancellation_reason);

        $suspendedRefund = Refund::where('booking_id', $suspendedBooking->id)->firstOrFail();
        $this->assertSame(RefundBasis::InstructorSuspended, $suspendedRefund->basis);
        $this->assertSame('1200.00', $suspendedRefund->amount);
        $this->assertSame(RefundStatus::Completed, $suspendedRefund->status);
    }

    #[Test]
    public function instructor_reschedule_gives_student_100_percent_until_accepted_then_applies_tiered_policy(): void
    {
        $actors = $this->createActors();

        // Booking 1: instructor rescheduled to tomorrow; student has NOT accepted -> cancelling yields 100%
        [$declinedBooking] = $this->createPaidGcashBooking($actors, daysUntilSession: 1, daysSincePayment: 2);
        $declinedBooking->update([
            'rescheduled_by_instructor_at' => $this->now->copy()->subHour(),
            'student_accepted_reschedule_at' => null,
        ]);

        $this->actingAs($actors['student'])
            ->post(route('student.bookings.cancel', $declinedBooking), [
                'reason' => 'New schedule does not fit my flight',
            ])
            ->assertRedirect();

        $declinedBooking->refresh();
        $this->assertSame(CancellationReason::RescheduleDeclined, $declinedBooking->cancellation_reason);
        $declinedRefund = Refund::where('booking_id', $declinedBooking->id)->firstOrFail();
        $this->assertSame(RefundBasis::InstructorRescheduleDeclined, $declinedRefund->basis);
        $this->assertSame('1200.00', $declinedRefund->amount);

        // Booking 2: instructor rescheduled to 4 days away; student accepts new date -> cancelling applies 50% tier
        [$acceptedBooking] = $this->createPaidGcashBooking($actors, daysUntilSession: 4, daysSincePayment: 2);
        $acceptedBooking->update([
            'rescheduled_by_instructor_at' => $this->now->copy()->subHour(),
            'student_accepted_reschedule_at' => null,
        ]);

        $this->actingAs($actors['student'])
            ->post(route('student.bookings.accept-reschedule', $acceptedBooking))
            ->assertRedirect();

        $this->assertNotNull($acceptedBooking->fresh()->student_accepted_reschedule_at);

        $this->actingAs($actors['student'])
            ->post(route('student.bookings.cancel', $acceptedBooking), [
                'reason' => 'Personal emergency after accepting reschedule',
            ])
            ->assertRedirect();

        $acceptedBooking->refresh();
        $this->assertSame(CancellationReason::Student, $acceptedBooking->cancellation_reason);
        $acceptedRefund = Refund::where('booking_id', $acceptedBooking->id)->firstOrFail();
        $this->assertSame(RefundBasis::ShortTermHalf, $acceptedRefund->basis);
        $this->assertSame('600.00', $acceptedRefund->amount);
        $this->assertSame('600.00', $acceptedRefund->instructor_retained_amount);
    }

    #[Test]
    public function paymongo_webhook_captures_payment_id_dispatches_refund_job_verifies_hmac_and_is_idempotent(): void
    {
        $webhookSecret = 'whsec_test_siasurf_secret';
        config([
            'services.paymongo.secret_key' => 'sk_test_siasurf_key',
            'services.paymongo.webhook_secret' => $webhookSecret,
        ]);

        $actors = $this->createActors();
        [$booking, $payment] = $this->createPaidGcashBooking(
            $actors,
            daysUntilSession: 10,
            daysSincePayment: 1,
            gatewayPaymentId: null,
            transactionId: 'cs_test_checkout_001',
        );
        // Simulate paid PayMongo checkout still awaiting payment_id capture
        $payment->update(['status' => PaymentStatus::Pending->value]);
        $booking->update(['payment_status' => PaymentStatus::Pending->value]);

        // 1. checkout.session.completed webhook captures pay_... payment ID
        $checkoutPayload = [
            'data' => [
                'id' => 'evt_checkout_001',
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout.session.completed',
                    'data' => [
                        'id' => 'cs_test_checkout_001',
                        'type' => 'checkout_session',
                        'attributes' => [
                            'reference_number' => 'cs_test_checkout_001',
                            'metadata' => ['booking_id' => $booking->id],
                            'payments' => [
                                ['id' => 'pay_live_98765', 'attributes' => ['status' => 'paid']],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $checkoutJson = json_encode($checkoutPayload, JSON_THROW_ON_ERROR);
        $ts = $this->now->timestamp;
        $checkoutSig = "t={$ts},te=" . hash_hmac('sha256', "{$ts}.{$checkoutJson}", $webhookSecret);

        $this->call(
            'POST',
            route('payments.webhook'),
            [],
            [],
            [],
            ['HTTP_PAYMONGO_SIGNATURE' => $checkoutSig, 'CONTENT_TYPE' => 'application/json'],
            $checkoutJson
        )->assertOk();

        $payment->refresh();
        $booking->refresh();
        $this->assertSame('pay_live_98765', $payment->gateway_payment_id);
        $this->assertSame(PaymentStatus::Completed, $payment->status);

        // 2. Fake PayMongo POST /v1/refunds -> status=pending -> Refund becomes Processing
        Http::fake([
            'https://api.paymongo.com/v1/refunds' => Http::response([
                'data' => [
                    'id' => 'ref_paymongo_777',
                    'type' => 'refund',
                    'attributes' => [
                        'status' => 'pending',
                        'amount' => 120000,
                        'payment_id' => 'pay_live_98765',
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($actors['student'])
            ->post(route('student.bookings.cancel', $booking), [
                'reason' => 'Cancelling PayMongo booking',
            ])
            ->assertRedirect();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.paymongo.com/v1/refunds'
                && ($request->data()['data']['attributes']['payment_id'] ?? null) === 'pay_live_98765'
                && ($request->data()['data']['attributes']['amount'] ?? null) === 120000;
        });

        $refund = Refund::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(RefundStatus::Processing, $refund->status);
        $this->assertSame('ref_paymongo_777', $refund->paymongo_refund_id);
        $this->assertSame(PaymentStatus::RefundProcessing, $payment->fresh()->status);

        // 3. Invalid signature webhook is rejected
        $refundWebhookJson = json_encode([
            'data' => [
                'id' => 'evt_refund_001',
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment.refund.updated',
                    'data' => [
                        'id' => 'ref_paymongo_777',
                        'type' => 'refund',
                        'attributes' => [
                            'status' => 'succeeded',
                            'payment_id' => 'pay_live_98765',
                            'amount' => 120000,
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $badResp = $this->call(
            'POST',
            route('payments.webhook'),
            [],
            [],
            [],
            ['HTTP_PAYMONGO_SIGNATURE' => "t={$ts},te=invalidsig", 'CONTENT_TYPE' => 'application/json'],
            $refundWebhookJson
        );
        $this->assertTrue(in_array($badResp->getStatusCode(), [400, 401, 403], true));
        $this->assertSame(RefundStatus::Processing, $refund->fresh()->status);

        // 4. Valid signature completes Refund & Payment
        $validRefundSig = "t={$ts},te=" . hash_hmac('sha256', "{$ts}.{$refundWebhookJson}", $webhookSecret);
        $this->call(
            'POST',
            route('payments.webhook'),
            [],
            [],
            [],
            ['HTTP_PAYMONGO_SIGNATURE' => $validRefundSig, 'CONTENT_TYPE' => 'application/json'],
            $refundWebhookJson
        )->assertOk();

        $this->assertSame(RefundStatus::Completed, $refund->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $booking->fresh()->payment_status);

        // 5. Replaying same webhook is idempotent
        $this->call(
            'POST',
            route('payments.webhook'),
            [],
            [],
            [],
            ['HTTP_PAYMONGO_SIGNATURE' => $validRefundSig, 'CONTENT_TYPE' => 'application/json'],
            $refundWebhookJson
        )->assertOk();
        $this->assertSame(RefundStatus::Completed, $refund->fresh()->status);
    }

    #[Test]
    public function admin_override_refund_retry_and_refund_ledger_index_work(): void
    {
        $actors = $this->createActors();

        // Student cancelled < 3 days before session (0% automatic refund), then Admin grants ₱600 override
        [$booking] = $this->createPaidGcashBooking($actors, daysUntilSession: 1, daysSincePayment: 2);
        $this->actingAs($actors['student'])
            ->post(route('student.bookings.cancel', $booking), ['reason' => 'Late cancellation'])
            ->assertRedirect();
        $this->assertSame(0, Refund::where('booking_id', $booking->id)->count());

        $this->actingAs($actors['admin'])
            ->post(route('payments.refund', $booking), [
                'amount' => 600,
                'reason' => 'Goodwill medical documentation override',
            ])
            ->assertRedirect();

        $overrideRefund = Refund::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(RefundBasis::AdminOverride, $overrideRefund->basis);
        $this->assertSame('600.00', $overrideRefund->amount);
        $this->assertSame(RefundStatus::Completed, $overrideRefund->status);

        // Retry a failed gateway refund
        [$retryBooking, $retryPayment] = $this->createPaidGcashBooking($actors, daysUntilSession: 12, daysSincePayment: 1);
        $failedRefund = Refund::create([
            'booking_id' => $retryBooking->id,
            'payment_id' => $retryPayment->id,
            'initiated_by' => $actors['student']->id,
            'lesson_price' => 1200.00,
            'service_fee_retained' => 84.00,
            'refund_percentage' => 100,
            'amount' => 1200.00,
            'instructor_retained_amount' => 0.00,
            'policy_type' => 'short_term',
            'basis' => RefundBasis::ShortTermFull,
            'days_before_session' => 12,
            'days_since_payment' => 1,
            'status' => RefundStatus::Failed,
            'gateway' => 'mock',
            'paymongo_payment_id' => 'mock_pay_abc123',
            'failure_reason' => 'Temporary gateway timeout',
            'requested_at' => $this->now->copy()->subHour(),
            'failed_at' => $this->now->copy()->subMinutes(30),
        ]);

        $this->actingAs($actors['admin'])
            ->post(route('admin.refunds.retry', $failedRefund))
            ->assertRedirect();

        $this->assertSame(RefundStatus::Completed, $failedRefund->fresh()->status);

        // Admin Refunds Index page renders stats and rows
        $this->actingAs($actors['admin'])
            ->get(route('admin.refunds.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Refunds/Index')
                ->has('refunds.data', 2)
                ->where('stats.completed', 2)
            );
    }

    #[Test]
    public function br_pay_05_refund_and_payment_records_are_immutable(): void
    {
        $actors = $this->createActors();
        [$booking, $payment] = $this->createPaidGcashBooking($actors, daysUntilSession: 10, daysSincePayment: 1);
        $this->actingAs($actors['student'])
            ->post(route('student.bookings.cancel', $booking), ['reason' => 'Immutability test cancel'])
            ->assertRedirect();

        $refund = Refund::where('booking_id', $booking->id)->firstOrFail();

        $this->assertFalse(Gate::forUser($actors['admin'])->allows('delete', $payment));
        $this->assertFalse(Gate::forUser($actors['admin'])->allows('delete', $refund));

        $this->expectException(\RuntimeException::class);
        $refund->delete();
    }

    #[Test]
    public function r12_student_no_show_sets_no_show_credits_lesson_price_and_notifies_student(): void
    {
        $actors = $this->createActors();
        [$booking] = $this->createPaidGcashBooking($actors, daysUntilSession: 0, daysSincePayment: 5);
        $booking->update([
            'start_time' => '07:00:00',
            'end_time' => '09:00:00',
        ]);

        $this->actingAs($actors['instructor'])
            ->post(route('instructor.bookings.attendance', $booking), [
                'attendance_status' => 'absent',
            ])
            ->assertRedirect();

        $booking->refresh();
        $this->assertSame(BookingStatus::NoShow, $booking->status);
        $this->assertSame(CancellationReason::StudentNoShow, $booking->cancellation_reason);
        $this->assertSame('0.00', $booking->refund_amount);
        $this->assertSame('1200.00', $booking->instructor_retained_amount);
        $this->assertSame(0, Refund::where('booking_id', $booking->id)->count());

        Notification::assertSentTo($actors['student'], BookingNoShowNotification::class);
    }

    #[Test]
    public function xendit_gcash_payment_and_refund_flow_works_with_redirect_verification_and_webhooks(): void
    {
        $webhookToken = 'xnd_webhook_secret_token_123';
        config([
            'services.xendit.secret_key' => 'xnd_development_test_key',
            'services.xendit.webhook_token' => $webhookToken,
        ]);

        $actors = $this->createActors();
        [$booking, $payment] = $this->createPaidGcashBooking(
            $actors,
            daysUntilSession: 10,
            daysSincePayment: 1,
            gatewayPaymentId: null,
            transactionId: 'ewc_test_charge_001',
        );

        $payment->update(['status' => PaymentStatus::Pending->value]);
        $booking->update([
            'status' => BookingStatus::AwaitingPayment->value,
            'payment_status' => PaymentStatus::Pending->value,
        ]);

        Http::fake([
            'https://api.xendit.co/ewallets/charges/ewc_test_charge_001' => Http::response([
                'id' => 'ewc_test_charge_001',
                'status' => 'SUCCEEDED',
                'currency' => 'PHP',
                'charge_amount' => 1284.00,
            ], 200),
            'https://api.xendit.co/ewallets/charges/ewc_test_charge_001/refunds' => Http::response([
                'id' => 'ewr_test_refund_001',
                'charge_id' => 'ewc_test_charge_001',
                'status' => 'PENDING',
                'currency' => 'PHP',
                'refund_amount' => 1200.00,
            ], 200),
        ]);

        // 1. Student returns from Xendit checkout to /payments/{booking}/success -> auto-verifies status & captures ewc_...
        $this->actingAs($actors['student'])
            ->get(route('payments.success', $booking))
            ->assertRedirect(route('bookings.confirmation', $booking));

        $payment->refresh();
        $booking->refresh();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(PaymentStatus::Completed, $payment->status);
        $this->assertSame('ewc_test_charge_001', $payment->gateway_payment_id);

        // 2. Student cancels booking -> dispatches Xendit refund (lesson price only, 1200.00)
        $this->actingAs($actors['student'])
            ->post(route('student.bookings.cancel', $booking), [
                'reason' => 'Cancelling Xendit GCash booking',
            ])
            ->assertRedirect();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.xendit.co/ewallets/charges/ewc_test_charge_001/refunds'
                && (float) ($request->data()['amount'] ?? 0) === 1200.00
                && ($request->data()['reason'] ?? null) === 'REQUESTED_BY_CUSTOMER';
        });

        $refund = Refund::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('xendit', $refund->gateway);
        $this->assertSame(RefundStatus::Processing, $refund->status);
        $this->assertSame('ewr_test_refund_001', $refund->paymongo_refund_id);

        // 3. Invalid x-callback-token webhook is rejected with 401
        $refundWebhookJson = json_encode([
            'event' => 'ewallet.refund',
            'data' => [
                'id' => 'ewr_test_refund_001',
                'charge_id' => 'ewc_test_charge_001',
                'status' => 'SUCCEEDED',
                'refund_amount' => 1200.00,
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            route('payments.webhook'),
            [],
            [],
            [],
            ['HTTP_X_CALLBACK_TOKEN' => 'wrong_token', 'CONTENT_TYPE' => 'application/json'],
            $refundWebhookJson
        )->assertStatus(401);

        $this->assertSame(RefundStatus::Processing, $refund->fresh()->status);

        // 4. Valid x-callback-token completes the Refund and Payment
        $this->call(
            'POST',
            route('payments.webhook'),
            [],
            [],
            [],
            ['HTTP_X_CALLBACK_TOKEN' => $webhookToken, 'CONTENT_TYPE' => 'application/json'],
            $refundWebhookJson
        )->assertOk();

        $this->assertSame(RefundStatus::Completed, $refund->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $booking->fresh()->payment_status);
    }
}
