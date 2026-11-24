<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\InstructorProfile;
use App\Models\Payment;
use App\Models\SurfSpot;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminDashboardRevenueTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $tz = config('app.timezone', 'Asia/Manila');
        $this->now = Carbon::create(2026, 11, 23, 12, 0, 0, $tz);
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
    public function net_revenue_and_chart_record_only_the_7_percent_tourist_service_fee(): void
    {
        $actors = $this->createActors();

        // Completed GCash booking paid this month (lesson ₱1,200 + 7% fee ₱84 = ₱1,284)
        $gcashBooking = Booking::factory()->withServiceFee(1200.00)->confirmed()->paid()->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
        ]);

        Payment::factory()->gcash()->completed()->create([
            'booking_id' => $gcashBooking->id,
            'amount' => 1284.00,
            'lesson_price' => 1200.00,
            'service_fee_amount' => 84.00,
            'paid_at' => $this->now->copy()->subDays(2),
            'created_at' => $this->now->copy()->subDays(2),
        ]);

        // Completed Cash booking paid 2 months ago (lesson ₱1,500 + 7% fee ₱105 = ₱1,605)
        $twoMonthsAgo = $this->now->copy()->startOfMonth()->subMonths(2)->addDays(10);
        $cashBooking = Booking::factory()->withServiceFee(1500.00)->confirmed()->paid()->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
        ]);

        Payment::factory()->cash()->completed()->create([
            'booking_id' => $cashBooking->id,
            'amount' => 1605.00,
            'lesson_price' => 1500.00,
            'service_fee_amount' => 105.00,
            'paid_at' => $twoMonthsAgo,
            'created_at' => $twoMonthsAgo,
        ]);

        $this->actingAs($actors['admin'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('stats.revenue.total', '₱189.00')
                ->where('stats.revenue.this_month', '₱84.00')
                ->where('stats.revenue.service_fee_percent', 7)
            );

        $response = $this->actingAs($actors['admin'])
            ->getJson(route('admin.api.dashboard.revenue'))
            ->assertOk();

        $labels = $response->json('revenue.labels');
        $data = $response->json('revenue.data');

        $this->assertCount(12, $labels);
        $this->assertCount(12, $data);

        $currentIdx = array_search($this->now->format('M Y'), $labels, true);
        $twoMonthsAgoIdx = array_search($twoMonthsAgo->format('M Y'), $labels, true);

        $this->assertNotFalse($currentIdx);
        $this->assertNotFalse($twoMonthsAgoIdx);
        $this->assertEquals(84.0, $data[$currentIdx]);
        $this->assertEquals(105.0, $data[$twoMonthsAgoIdx]);

        foreach ($data as $idx => $value) {
            if ($idx !== $currentIdx && $idx !== $twoMonthsAgoIdx) {
                $this->assertEquals(0.0, $value);
            }
        }
    }

    #[Test]
    public function retained_service_fees_from_refund_statuses_remain_in_net_revenue(): void
    {
        $actors = $this->createActors();

        $statusesAndFees = [
            [PaymentStatus::RefundRequested, 84.00, 1200.00],
            [PaymentStatus::RefundProcessing, 56.00, 800.00],
            [PaymentStatus::Refunded, 42.00, 600.00],
        ];

        foreach ($statusesAndFees as [$status, $fee, $lessonPrice]) {
            $booking = Booking::factory()->withServiceFee($lessonPrice)->cancelled()->create([
                'student_id' => $actors['student']->id,
                'instructor_id' => $actors['instructor']->id,
                'surf_spot_id' => $actors['spot']->id,
                'payment_status' => $status->value,
            ]);

            Payment::factory()->gcash()->create([
                'booking_id' => $booking->id,
                'amount' => $lessonPrice + $fee,
                'lesson_price' => $lessonPrice,
                'service_fee_amount' => $fee,
                'status' => $status->value,
                'paid_at' => $this->now->copy()->subDay(),
            ]);
        }

        $this->actingAs($actors['admin'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.revenue.total', '₱182.00')
                ->where('stats.revenue.this_month', '₱182.00')
            );
    }

    #[Test]
    public function unpaid_unverified_failed_and_underpaid_payments_are_excluded_from_net_revenue(): void
    {
        $actors = $this->createActors();

        $excludedStatuses = [
            PaymentStatus::Pending,
            PaymentStatus::PendingCash,
            PaymentStatus::PendingVerification,
            PaymentStatus::Failed,
            PaymentStatus::Underpaid,
        ];

        foreach ($excludedStatuses as $status) {
            $booking = Booking::factory()->withServiceFee(1200.00)->create([
                'student_id' => $actors['student']->id,
                'instructor_id' => $actors['instructor']->id,
                'surf_spot_id' => $actors['spot']->id,
                'payment_status' => $status->value,
            ]);

            Payment::factory()->create([
                'booking_id' => $booking->id,
                'amount' => 1284.00,
                'lesson_price' => 1200.00,
                'service_fee_amount' => 84.00,
                'status' => $status->value,
                'paid_at' => null,
            ]);
        }

        $this->actingAs($actors['admin'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.revenue.total', '₱0.00')
                ->where('stats.revenue.this_month', '₱0.00')
            );
    }

    #[Test]
    public function historical_zero_fee_payments_contribute_zero_while_missing_payment_fee_falls_back_to_booking(): void
    {
        $actors = $this->createActors();

        // Pre-service-fee historical booking/payment (both 0.00)
        $legacyBooking = Booking::factory()->confirmed()->paid()->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
            'lesson_price' => 1200.00,
            'service_fee_amount' => 0.00,
            'total_amount' => 1200.00,
        ]);

        Payment::factory()->gcash()->completed()->create([
            'booking_id' => $legacyBooking->id,
            'amount' => 1200.00,
            'lesson_price' => 1200.00,
            'service_fee_amount' => 0.00,
            'paid_at' => $this->now->copy()->subDay(),
        ]);

        // Payment row has 0.00 service_fee_amount, but parent booking recorded 84.00
        $fallbackBooking = Booking::factory()->withServiceFee(1200.00)->confirmed()->paid()->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
        ]);

        Payment::factory()->cash()->completed()->create([
            'booking_id' => $fallbackBooking->id,
            'amount' => 1284.00,
            'lesson_price' => 1200.00,
            'service_fee_amount' => 0.00,
            'paid_at' => $this->now->copy()->subDay(),
        ]);

        $this->actingAs($actors['admin'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.revenue.total', '₱84.00')
                ->where('stats.revenue.this_month', '₱84.00')
            );
    }

    #[Test]
    public function cash_settlement_persists_service_fee_amount_and_reflects_in_admin_net_revenue(): void
    {
        $actors = $this->createActors();

        $booking = Booking::factory()->withServiceFee(1200.00)->confirmed()->create([
            'student_id' => $actors['student']->id,
            'instructor_id' => $actors['instructor']->id,
            'surf_spot_id' => $actors['spot']->id,
            'payment_status' => PaymentStatus::PendingCash->value,
        ]);

        $this->actingAs($actors['instructor'])
            ->post(route('payments.cash', $booking), [
                'amount_received' => 1284.00,
            ])
            ->assertRedirect();

        $payment = $booking->fresh()->payment;
        $this->assertNotNull($payment);
        $this->assertSame(PaymentMethod::Cash, $payment->payment_method);
        $this->assertSame(PaymentStatus::Completed, $payment->status);
        $this->assertSame('1200.00', $payment->lesson_price);
        $this->assertSame('84.00', $payment->service_fee_amount);

        $this->actingAs($actors['admin'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.revenue.total', '₱84.00')
                ->where('stats.revenue.this_month', '₱84.00')
            );
    }
}
