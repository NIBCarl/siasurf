<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\LedgerSettlementStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PayoutChannel;
use App\Enums\PayoutType;
use App\Models\Booking;
use App\Models\InstructorDailyLedger;
use App\Models\InstructorPayout;
use App\Models\InstructorProfile;
use App\Models\Payment;
use App\Models\SurfSpot;
use App\Models\User;
use App\Notifications\CashRemittanceOverdueNotification;
use App\Notifications\PayoutRecordedNotification;
use App\Services\EarningsLedgerService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EarningsLedgerAndPayoutsTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();

        $tz = config('app.timezone', 'Asia/Manila');
        $this->now = Carbon::create(2026, 11, 24, 10, 0, 0, $tz);
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{admin: User, instructor: User, student: User, spot: SurfSpot}
     */
    private function createActors(): array
    {
        $admin = User::factory()->admin()->create();
        $admin->assignRole('admin');

        $instructor = User::factory()->instructor()->create();
        $instructor->assignRole('instructor');
        InstructorProfile::factory()->level1()->for($instructor, 'user')->create();

        $student = User::factory()->student()->create();
        $student->assignRole('student');

        $spot = SurfSpot::factory()->create();

        return compact('admin', 'instructor', 'student', 'spot');
    }

    #[Test]
    public function completing_a_gcash_lesson_creates_a_daily_ledger_entry_with_no_cash_fee_owed(): void
    {
        $actors = $this->createActors();

        $booking = Booking::factory()->withServiceFee(1200.00)->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
            'date' => $this->now->toDateString(),
            'status' => BookingStatus::InProgress->value,
            'payment_status' => PaymentStatus::Completed->value,
            'started_at' => $this->now->copy()->subHours(2),
        ]);

        Payment::factory()->gcash()->completed()->create([
            'booking_id' => $booking->id,
            'amount' => 1284.00,
            'lesson_price' => 1200.00,
            'service_fee_amount' => 84.00,
            'paid_at' => $this->now->copy()->subDay(),
        ]);

        $this->actingAs($actors['instructor'])
            ->post(route('instructor.bookings.complete-session', $booking))
            ->assertRedirect();

        $ledger = InstructorDailyLedger::query()
            ->where('instructor_id', $actors['instructor']->id)
            ->first();

        $this->assertNotNull($ledger);
        $this->assertSame($this->now->toDateString(), $ledger->ledger_date->toDateString());
        $this->assertSame(1, $ledger->completed_bookings_count);
        $this->assertSame(1, $ledger->gcash_bookings_count);
        $this->assertSame(0, $ledger->cash_bookings_count);
        $this->assertSame('1200.00', $ledger->gcash_earnings);
        $this->assertSame('0.00', $ledger->cash_earnings);
        $this->assertSame('84.00', $ledger->platform_gcash_fees);
        $this->assertSame('0.00', $ledger->cash_service_fees_owed);
        $this->assertSame('1200.00', $ledger->net_payable);
        $this->assertNull($ledger->remittance_due_date);
        $this->assertSame(LedgerSettlementStatus::Unsettled, $ledger->settlement_status);
        $this->assertSame($ledger->id, $booking->fresh()->daily_ledger_id);
    }

    #[Test]
    public function settling_a_cash_lesson_records_cash_service_fee_owed_and_7_day_remittance_due_date(): void
    {
        $actors = $this->createActors();

        $booking = Booking::factory()->withServiceFee(1200.00)->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
            'date' => $this->now->toDateString(),
            'status' => BookingStatus::InProgress->value,
            'payment_status' => PaymentStatus::PendingCash->value,
            'started_at' => $this->now->copy()->subHours(2),
        ]);

        Payment::factory()->cash()->create([
            'booking_id' => $booking->id,
            'amount' => 1284.00,
            'lesson_price' => 1200.00,
            'service_fee_amount' => 84.00,
            'status' => PaymentStatus::PendingCash->value,
        ]);

        $this->actingAs($actors['instructor'])
            ->post(route('instructor.bookings.complete-session', $booking), [
                'cash_received' => true,
            ])
            ->assertRedirect();

        $ledger = InstructorDailyLedger::query()
            ->where('instructor_id', $actors['instructor']->id)
            ->first();

        $this->assertNotNull($ledger);
        $this->assertSame(0, $ledger->gcash_bookings_count);
        $this->assertSame(1, $ledger->cash_bookings_count);
        $this->assertSame('0.00', $ledger->gcash_earnings);
        $this->assertSame('1200.00', $ledger->cash_earnings);
        $this->assertSame('84.00', $ledger->cash_service_fees_owed);
        $this->assertSame('-84.00', $ledger->net_payable);
        $this->assertSame(
            $this->now->copy()->addDays(7)->toDateString(),
            $ledger->remittance_due_date?->toDateString()
        );
    }

    #[Test]
    public function underpaid_cash_lesson_via_mark_as_paid_still_owes_full_7_percent_service_fee(): void
    {
        $actors = $this->createActors();

        $booking = Booking::factory()->withServiceFee(1200.00)->completed()->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
            'date' => $this->now->toDateString(),
            'completed_at' => $this->now,
            'payment_status' => PaymentStatus::PendingCash->value,
        ]);

        Payment::factory()->cash()->create([
            'booking_id' => $booking->id,
            'amount' => 1284.00,
            'lesson_price' => 1200.00,
            'service_fee_amount' => 84.00,
            'status' => PaymentStatus::PendingCash->value,
        ]);

        $this->actingAs($actors['instructor'])
            ->post(route('payments.cash', $booking), [
                'amount_received' => 1000.00,
                'notes' => 'Student only had 1000 PHP cash on hand',
            ])
            ->assertRedirect();

        $ledger = InstructorDailyLedger::query()->first();
        $this->assertNotNull($ledger);
        $this->assertSame('84.00', $ledger->cash_service_fees_owed);
        $this->assertSame('916.00', $ledger->cash_earnings);
        $this->assertSame('-84.00', $ledger->net_payable);
    }

    #[Test]
    public function same_day_gcash_and_cash_lessons_aggregate_into_single_daily_row_and_offset_payable(): void
    {
        $actors = $this->createActors();

        // Lesson 1: GCash ₱1,200 (SISA holds ₱1,200 + ₱84 fee)
        $gcashBooking = Booking::factory()->withServiceFee(1200.00)->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
            'date' => $this->now->toDateString(),
            'status' => BookingStatus::InProgress->value,
            'payment_status' => PaymentStatus::Completed->value,
            'started_at' => $this->now->copy()->subHours(4),
        ]);
        Payment::factory()->gcash()->completed()->create([
            'booking_id' => $gcashBooking->id,
            'amount' => 1284.00,
            'lesson_price' => 1200.00,
            'service_fee_amount' => 84.00,
            'paid_at' => $this->now->copy()->subHours(5),
        ]);

        // Lesson 2: Cash ₱1,500 (Instructor holds ₱1,605; owes SISA ₱105 fee)
        $cashBooking = Booking::factory()->withServiceFee(1500.00)->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
            'date' => $this->now->toDateString(),
            'status' => BookingStatus::InProgress->value,
            'payment_status' => PaymentStatus::PendingCash->value,
            'started_at' => $this->now->copy()->subHours(2),
        ]);
        Payment::factory()->cash()->create([
            'booking_id' => $cashBooking->id,
            'amount' => 1605.00,
            'lesson_price' => 1500.00,
            'service_fee_amount' => 105.00,
            'status' => PaymentStatus::PendingCash->value,
        ]);

        $this->actingAs($actors['instructor'])
            ->post(route('instructor.bookings.complete-session', $gcashBooking))
            ->assertRedirect();

        $this->actingAs($actors['instructor'])
            ->post(route('instructor.bookings.complete-session', $cashBooking), ['cash_received' => true])
            ->assertRedirect();

        $this->assertSame(1, InstructorDailyLedger::count());
        $ledger = InstructorDailyLedger::first();
        $this->assertSame(2, $ledger->completed_bookings_count);
        $this->assertSame('1200.00', $ledger->gcash_earnings);
        $this->assertSame('1500.00', $ledger->cash_earnings);
        $this->assertSame('2700.00', $ledger->total_earnings);
        $this->assertSame('105.00', $ledger->cash_service_fees_owed);
        // Payable = GCash earnings (1200) - Cash fees owed (105) = 1095.00
        $this->assertSame('1095.00', $ledger->net_payable);
    }

    #[Test]
    public function cancellation_retention_and_admin_override_refund_update_the_daily_ledger(): void
    {
        $actors = $this->createActors();

        // Paid GCash booking 4 days away -> 50% refund, 50% (₱600) retained by instructor
        $booking = Booking::factory()->withServiceFee(1200.00)->confirmed()->paid()->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
            'date' => $this->now->copy()->addDays(4)->toDateString(),
            'original_date' => $this->now->copy()->addDays(4)->toDateString(),
            'is_long_term' => false,
            'created_at' => $this->now->copy()->subDays(2),
        ]);

        Payment::factory()->gcash()->completed()->create([
            'booking_id' => $booking->id,
            'amount' => 1284.00,
            'lesson_price' => 1200.00,
            'service_fee_amount' => 84.00,
            'transaction_id' => 'mock_cs_ret1',
            'gateway_payment_id' => 'mock_pay_ret1',
            'paid_at' => $this->now->copy()->subDays(2),
        ]);

        $this->actingAs($actors['student'])
            ->post(route('student.bookings.cancel', $booking), ['reason' => 'Cannot make it'])
            ->assertRedirect();

        $ledger = InstructorDailyLedger::first();
        $this->assertNotNull($ledger);
        $this->assertSame('600.00', $ledger->gcash_earnings);
        $this->assertSame('600.00', $ledger->net_payable);
    }

    #[Test]
    public function student_no_show_on_paid_gcash_credits_full_lesson_price_to_daily_ledger(): void
    {
        $actors = $this->createActors();

        $booking = Booking::factory()->withServiceFee(1200.00)->confirmed()->paid()->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
            'date' => $this->now->toDateString(),
            'start_time' => $this->now->copy()->subHours(4)->format('H:i'),
        ]);

        Payment::factory()->gcash()->completed()->create([
            'booking_id' => $booking->id,
            'amount' => 1284.00,
            'lesson_price' => 1200.00,
            'service_fee_amount' => 84.00,
            'paid_at' => $this->now->copy()->subDay(),
        ]);

        $this->actingAs($actors['instructor'])
            ->post(route('instructor.bookings.attendance', $booking), [
                'attendance_status' => AttendanceStatus::Absent->value,
            ])
            ->assertRedirect();

        $booking->refresh();
        $this->assertSame(BookingStatus::NoShow, $booking->status);
        $this->assertSame('1200.00', $booking->instructor_retained_amount);

        $ledger = InstructorDailyLedger::first();
        $this->assertNotNull($ledger);
        $this->assertSame('1200.00', $ledger->gcash_earnings);
    }

    #[Test]
    public function admin_can_record_in_person_payout_remittance_and_offset_settlements(): void
    {
        $actors = $this->createActors();
        $ledgerService = app(EarningsLedgerService::class);

        // Day 1: GCash 1200, Cash fee 105 => Net +1095 (Payout)
        $day1 = InstructorDailyLedger::create([
            'instructor_id' => $actors['instructor']->id,
            'ledger_date' => $this->now->copy()->subDays(2)->toDateString(),
            'completed_bookings_count' => 2,
            'gcash_bookings_count' => 1,
            'cash_bookings_count' => 1,
            'gcash_earnings' => 1200.00,
            'cash_earnings' => 1500.00,
            'total_earnings' => 2700.00,
            'platform_gcash_fees' => 84.00,
            'cash_service_fees_owed' => 105.00,
            'net_payable' => 1095.00,
            'remittance_due_date' => $this->now->copy()->addDays(5)->toDateString(),
            'settlement_status' => LedgerSettlementStatus::Unsettled->value,
        ]);

        $this->actingAs($actors['admin'])
            ->post(route('admin.payouts.store', $actors['instructor']), [
                'payment_channel' => PayoutChannel::InPersonCash->value,
                'receipt_reference' => 'OR-2026-001',
                'notes' => 'Paid at SISA office',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $payout = InstructorPayout::first();
        $this->assertNotNull($payout);
        $this->assertSame(PayoutType::Payout, $payout->settlement_type);
        $this->assertSame(PayoutChannel::InPersonCash, $payout->payment_channel);
        $this->assertSame('1200.00', $payout->gcash_earnings_settled);
        $this->assertSame('105.00', $payout->cash_fees_settled);
        $this->assertSame('1095.00', $payout->net_payout_amount);
        $this->assertSame($actors['admin']->id, $payout->recorded_by);

        $day1->refresh();
        $this->assertSame(LedgerSettlementStatus::Settled, $day1->settlement_status);
        $this->assertSame($payout->id, $day1->payout_id);

        Notification::assertSentTo($actors['instructor'], PayoutRecordedNotification::class);

        // Payout records are immutable
        $this->expectException(\RuntimeException::class);
        $payout->delete();
    }

    #[Test]
    public function admin_settlement_supports_cash_fee_remittance_zero_cash_offset_and_same_day_new_open_ledger(): void
    {
        $actors = $this->createActors();

        // Case A: Net negative => Remittance
        InstructorDailyLedger::create([
            'instructor_id' => $actors['instructor']->id,
            'ledger_date' => $this->now->toDateString(),
            'completed_bookings_count' => 1,
            'cash_bookings_count' => 1,
            'gcash_earnings' => 0.00,
            'cash_earnings' => 1200.00,
            'total_earnings' => 1200.00,
            'cash_service_fees_owed' => 84.00,
            'net_payable' => -84.00,
            'remittance_due_date' => $this->now->copy()->addDays(7)->toDateString(),
            'settlement_status' => LedgerSettlementStatus::Unsettled->value,
        ]);

        $this->actingAs($actors['admin'])
            ->post(route('admin.payouts.store', $actors['instructor']), [
                'payment_channel' => PayoutChannel::InPersonCash->value,
                'receipt_reference' => 'REM-001',
            ])
            ->assertRedirect();

        $remittance = InstructorPayout::latest('id')->first();
        $this->assertSame(PayoutType::Remittance, $remittance->settlement_type);
        $this->assertSame('-84.00', $remittance->net_payout_amount);

        // Later on the SAME calendar date, instructor completes a GCash lesson.
        // Must create a NEW open daily ledger row rather than mutating the settled row.
        $afternoonBooking = Booking::factory()->withServiceFee(1000.00)->completed()->paid()->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
            'date' => $this->now->toDateString(),
            'completed_at' => $this->now,
        ]);
        Payment::factory()->gcash()->completed()->create([
            'booking_id' => $afternoonBooking->id,
            'amount' => 1070.00,
            'lesson_price' => 1000.00,
            'service_fee_amount' => 70.00,
            'paid_at' => $this->now,
        ]);

        $openRow = app(EarningsLedgerService::class)->syncBookingToLedger($afternoonBooking->fresh(['payment']));
        $this->assertNotNull($openRow);
        $this->assertNull($openRow->payout_id);
        $this->assertSame(2, InstructorDailyLedger::where('instructor_id', $actors['instructor']->id)->count());

        // Case B: Add a matching cash fee of 1000 on another day so GCash (1000) - Cash Fee (1000) = 0 => Offset
        InstructorDailyLedger::create([
            'instructor_id' => $actors['instructor']->id,
            'ledger_date' => $this->now->copy()->addDay()->toDateString(),
            'completed_bookings_count' => 1,
            'cash_bookings_count' => 1,
            'gcash_earnings' => 0.00,
            'cash_earnings' => 14285.71,
            'total_earnings' => 14285.71,
            'cash_service_fees_owed' => 1000.00,
            'net_payable' => -1000.00,
            'remittance_due_date' => $this->now->copy()->addDays(8)->toDateString(),
            'settlement_status' => LedgerSettlementStatus::Unsettled->value,
        ]);

        $this->actingAs($actors['admin'])
            ->post(route('admin.payouts.store', $actors['instructor']), [])
            ->assertRedirect();

        $offset = InstructorPayout::latest('id')->first();
        $this->assertSame(PayoutType::Offset, $offset->settlement_type);
        $this->assertSame(PayoutChannel::Offset, $offset->payment_channel);
        $this->assertSame('0.00', $offset->net_payout_amount);
    }

    #[Test]
    public function overdue_remittance_command_flags_rows_and_notifies_instructor_once(): void
    {
        $actors = $this->createActors();

        $overdueLedger = InstructorDailyLedger::create([
            'instructor_id' => $actors['instructor']->id,
            'ledger_date' => $this->now->copy()->subDays(10)->toDateString(),
            'completed_bookings_count' => 1,
            'cash_bookings_count' => 1,
            'gcash_earnings' => 0.00,
            'cash_earnings' => 1200.00,
            'total_earnings' => 1200.00,
            'cash_service_fees_owed' => 84.00,
            'net_payable' => -84.00,
            'remittance_due_date' => $this->now->copy()->subDays(3)->toDateString(),
            'settlement_status' => LedgerSettlementStatus::Unsettled->value,
        ]);

        $this->artisan('ledger:check-remittances')
            ->assertSuccessful();

        $overdueLedger->refresh();
        $this->assertSame(LedgerSettlementStatus::Overdue, $overdueLedger->settlement_status);
        $this->assertNotNull($overdueLedger->overdue_notified_at);

        Notification::assertSentToTimes($actors['instructor'], CashRemittanceOverdueNotification::class, 1);

        // Second run must not duplicate notifications
        $this->artisan('ledger:check-remittances')
            ->assertSuccessful();

        Notification::assertSentToTimes($actors['instructor'], CashRemittanceOverdueNotification::class, 1);
    }

    #[Test]
    public function backfill_command_populates_ledger_idempotently(): void
    {
        $actors = $this->createActors();

        $booking = Booking::factory()->withServiceFee(1200.00)->completed()->paid()->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
            'date' => $this->now->copy()->subDay()->toDateString(),
            'completed_at' => $this->now->copy()->subDay(),
        ]);

        Payment::factory()->gcash()->completed()->create([
            'booking_id' => $booking->id,
            'amount' => 1284.00,
            'lesson_price' => 1200.00,
            'service_fee_amount' => 84.00,
            'paid_at' => $this->now->copy()->subDay(),
        ]);

        $this->artisan('ledger:backfill')->assertSuccessful();
        $this->assertSame(1, InstructorDailyLedger::count());
        $this->assertSame('1200.00', InstructorDailyLedger::first()->gcash_earnings);

        // Running again should be a no-op
        $this->artisan('ledger:backfill')->assertSuccessful();
        $this->assertSame(1, InstructorDailyLedger::count());
    }

    #[Test]
    public function authorization_and_inertia_pages_render_correctly_for_instructor_and_admin(): void
    {
        $actors = $this->createActors();

        InstructorDailyLedger::create([
            'instructor_id' => $actors['instructor']->id,
            'ledger_date' => $this->now->toDateString(),
            'completed_bookings_count' => 1,
            'gcash_bookings_count' => 1,
            'gcash_earnings' => 1200.00,
            'total_earnings' => 1200.00,
            'platform_gcash_fees' => 84.00,
            'net_payable' => 1200.00,
            'settlement_status' => LedgerSettlementStatus::Unsettled->value,
        ]);

        // Students & instructors cannot access admin payout routes
        $this->actingAs($actors['student'])
            ->get(route('admin.payouts.index'))
            ->assertForbidden();

        $this->actingAs($actors['instructor'])
            ->get(route('admin.payouts.index'))
            ->assertForbidden();

        $this->actingAs($actors['instructor'])
            ->post(route('admin.payouts.store', $actors['instructor']), [])
            ->assertForbidden();

        // Instructor views their dashboard & earnings ledger
        $this->actingAs($actors['instructor'])
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Instructor/Dashboard')
                ->where('earnings.net_payable', 1200)
            );

        $this->actingAs($actors['instructor'])
            ->get(route('instructor.earnings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Instructor/Earnings/Index')
                ->where('summary.unsettled_gcash_earnings', 1200)
                ->has('ledgers.data', 1)
            );

        // Admin views payouts index, instructor ledger detail, and instructor dossier
        $this->actingAs($actors['admin'])
            ->get(route('admin.payouts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Payouts/Index')
                ->where('platformStats.total_unsettled_gcash_payable', 1200)
                ->has('instructors', 1)
            );

        $this->actingAs($actors['admin'])
            ->get(route('admin.payouts.show', $actors['instructor']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Payouts/Show')
                ->where('summary.net_payable', 1200)
                ->has('unsettledLedgers', 1)
            );

        $this->actingAs($actors['admin'])
            ->get(route('admin.instructors.show', $actors['instructor']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Instructors/Show')
                ->where('ledgerSummary.net_payable', 1200)
            );
    }
}
