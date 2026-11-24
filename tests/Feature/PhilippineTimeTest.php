<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\InstructorLevel;
use App\Enums\LedgerSettlementStatus;
use App\Enums\PayoutChannel;
use App\Enums\PayoutType;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\InstructorDailyLedger;
use App\Models\InstructorPayout;
use App\Models\SurfSpot;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PhilippineTimeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function application_and_database_session_use_philippine_time(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
        $this->assertSame('Asia/Manila', date_default_timezone_get());

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $row = DB::selectOne('SELECT @@session.time_zone AS tz');
            $this->assertSame('+08:00', $row->tz);
        }
    }

    #[Test]
    public function date_casts_serialize_as_plain_calendar_dates_without_utc_day_shift(): void
    {
        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        $instructor = User::factory()->create(['role' => 'instructor']);
        $instructor->assignRole('instructor');
        $instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 1200,
        ]);

        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');

        $spot = SurfSpot::factory()->create(['difficulty' => 'beginner']);

        $availability = Availability::create([
            'user_id' => $instructor->id,
            'day_of_week' => 2,
            'time_period' => 'morning',
            'specific_date' => '2026-11-24',
            'is_available' => false,
        ]);

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'surf_spot_id' => $spot->id,
            'date' => '2026-11-25',
            'original_date' => '2026-11-24',
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
            'student_count' => 1,
            'student_age' => 25,
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
            'status' => BookingStatus::Confirmed,
        ]);

        $changeRequest = BookingChangeRequest::create([
            'booking_id' => $booking->id,
            'requested_by' => $instructor->id,
            'type' => ChangeRequestType::Reschedule,
            'status' => ChangeRequestStatus::Pending,
            'requires_admin_approval' => false,
            'reason' => 'Tide shift',
            'original_date' => '2026-11-24',
            'original_time_period' => 'morning',
            'original_start_time' => '08:00',
            'original_end_time' => '10:00',
            'new_date' => '2026-11-26',
            'new_time_period' => 'afternoon',
            'new_start_time' => '14:00',
            'new_end_time' => '16:00',
        ]);

        $payout = InstructorPayout::create([
            'instructor_id' => $instructor->id,
            'recorded_by' => $instructor->id,
            'reference_code' => 'SISA-PAY-20261124-TEST',
            'settlement_type' => PayoutType::Payout,
            'payment_channel' => PayoutChannel::InPersonCash,
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-24',
            'ledger_days_count' => 1,
            'gcash_earnings_settled' => 1200,
            'cash_fees_settled' => 0,
            'net_payout_amount' => 1200,
            'paid_at' => now(),
        ]);

        $ledger = InstructorDailyLedger::create([
            'instructor_id' => $instructor->id,
            'ledger_date' => '2026-11-24',
            'completed_bookings_count' => 1,
            'gcash_bookings_count' => 1,
            'cash_bookings_count' => 0,
            'gcash_earnings' => 1200,
            'cash_earnings' => 0,
            'total_earnings' => 1200,
            'platform_gcash_fees' => 84,
            'cash_service_fees_owed' => 0,
            'net_payable' => 1200,
            'remittance_due_date' => '2026-12-01',
            'settlement_status' => LedgerSettlementStatus::Unsettled,
        ]);

        $availabilityArr = $availability->fresh()->toArray();
        $bookingArr = $booking->fresh()->toArray();
        $changeArr = $changeRequest->fresh()->toArray();
        $payoutArr = $payout->fresh()->toArray();
        $ledgerArr = $ledger->fresh()->toArray();

        $this->assertSame('2026-11-24', $availabilityArr['specific_date']);
        $this->assertSame('2026-11-25', $bookingArr['date']);
        $this->assertSame('2026-11-24', $bookingArr['original_date']);
        $this->assertSame('2026-11-24', $changeArr['original_date']);
        $this->assertSame('2026-11-26', $changeArr['new_date']);
        $this->assertSame('2026-11-01', $payoutArr['period_start']);
        $this->assertSame('2026-11-24', $payoutArr['period_end']);
        $this->assertSame('2026-11-24', $ledgerArr['ledger_date']);
        $this->assertSame('2026-12-01', $ledgerArr['remittance_due_date']);
    }

    #[Test]
    public function early_morning_philippine_time_uses_manila_today_not_previous_utc_day(): void
    {
        Role::findOrCreate('instructor', 'web');

        $instructor = User::factory()->create(['role' => 'instructor']);
        $instructor->assignRole('instructor');
        $instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level1,
            'status' => 'active',
            'rate_per_hour' => 1000,
        ]);

        foreach (range(0, 6) as $day) {
            foreach (['morning', 'afternoon'] as $period) {
                Availability::create([
                    'user_id' => $instructor->id,
                    'day_of_week' => $day,
                    'time_period' => $period,
                    'is_available' => true,
                ]);
            }
        }

        // 2026-11-24 01:30:00 Asia/Manila == 2026-11-23 17:30:00 UTC.
        $this->travelTo(Carbon::parse('2026-11-24 01:30:00', 'Asia/Manila'));

        $service = app(AvailabilityService::class);

        $weekSchedule = $service->instructorWeekSchedule($instructor->id);
        $todayEntry = collect($weekSchedule['days'])->firstWhere('date', '2026-11-24');
        $yesterdayEntry = collect($weekSchedule['days'])->firstWhere('date', '2026-11-23');

        $this->assertNotNull($todayEntry);
        $this->assertTrue($todayEntry['is_today']);
        if ($yesterdayEntry !== null) {
            $this->assertFalse($yesterdayEntry['is_today']);
        }

        $calendar = $service->availabilityByDay($instructor->id, now(), 3);
        $this->assertArrayHasKey('2026-11-24', $calendar);
        $this->assertArrayNotHasKey('2026-11-23', $calendar);
    }
}
