<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\InstructorLevel;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The booking calendar needs availability for a whole month at once.
 *
 * availabilityByDay() must agree with periodsFor() (the single-date source of
 * truth) day by day, otherwise the calendar greys out dates the student could
 * actually book - or worse, offers dates that then fail on submit.
 */
class AvailabilityCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;
    private SurfSpot $surfSpot;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level3,
            'status' => 'active',
            'rate_per_hour' => 1500,
        ]);

        $this->surfSpot = SurfSpot::factory()->create(['difficulty' => 'beginner']);

        // Publish a weekly pattern: every day, both periods.
        foreach (range(0, 6) as $day) {
            foreach (['morning', 'afternoon'] as $period) {
                Availability::create([
                    'user_id' => $this->instructor->id,
                    'day_of_week' => $day,
                    'time_period' => $period,
                    'is_available' => true,
                ]);
            }
        }
    }

    private function service(): AvailabilityService
    {
        return app(AvailabilityService::class);
    }

    /** @test */
    public function a_blocked_date_is_unavailable_for_the_whole_day()
    {
        $blocked = now()->addDays(3)->format('Y-m-d');

        Availability::create([
            'user_id' => $this->instructor->id,
            'day_of_week' => (int) now()->addDays(3)->format('w'),
            'specific_date' => $blocked,
            'time_period' => 'morning',
            'is_available' => false,
        ]);

        $map = $this->service()->availabilityByDay($this->instructor->id, now(), 10);

        $this->assertFalse($map[$blocked]['morning']['available']);
        $this->assertStringContainsString('personal time', $map[$blocked]['morning']['reason']);

        // The other period on the same date is untouched.
        $this->assertTrue($map[$blocked]['afternoon']['available']);
    }

    /** @test */
    public function a_day_off_in_the_weekly_pattern_is_unavailable()
    {
        $date = now()->addDays(2);
        $weekday = (int) $date->format('w');

        // Turn off both periods for that weekday.
        Availability::where('user_id', $this->instructor->id)
            ->whereNull('specific_date')
            ->where('day_of_week', $weekday)
            ->update(['is_available' => false]);

        $map = $this->service()->availabilityByDay($this->instructor->id, now(), 10);
        $key = $date->format('Y-m-d');

        $this->assertFalse($map[$key]['morning']['available']);
        $this->assertStringContainsString('Not available', $map[$key]['morning']['reason']);
    }

    /** @test */
    public function the_batch_result_agrees_with_the_single_date_endpoint()
    {
        // Freeze the clock. Without this the test straddles a real "now": the
        // batch resolves lead time once for the whole window while periodsFor()
        // evaluates it per call, so the two can disagree by a minute near a
        // boundary - and the failure only appears at certain times of day.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-12-01 08:00:00'));

        $map = $this->service()->availabilityByDay($this->instructor->id, now(), 14);

        foreach ($map as $date => $periods) {
            $single = $this->service()->periodsFor($this->instructor->id, $date);

            foreach (['morning', 'afternoon'] as $period) {
                $this->assertSame(
                    $single[$period]['available'],
                    $periods[$period]['available'],
                    "Availability disagreed for {$date} {$period}"
                );
            }
        }
    }

    /**
     * Regression: the batch used to test the lead-time cut-off against the
     * period START rather than each candidate slot. At 08:00 the 06:00 start was
     * inside the one-hour buffer, so the whole morning was marked unbookable
     * even though 10:00 and 11:00 were still open. The student saw a greyed-out
     * day that the single-date endpoint said was bookable.
     *
     * @test
     */
    public function a_morning_stays_bookable_when_later_slots_are_still_open()
    {
        // 08:00 today: 06:00-09:00 starts are inside the buffer, 10:00 and
        // 11:00 are not.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-12-01 08:00:00'));

        $map = $this->service()->availabilityByDay($this->instructor->id, today(), 1);
        $single = $this->service()->periodsFor($this->instructor->id, today());

        $this->assertTrue(
            $map['2026-12-01']['morning']['available'],
            'The morning has bookable starts after the cut-off, so it must not be closed.'
        );

        $this->assertTrue($single['morning']['available']);
        $this->assertNotEmpty($single['morning']['slots']);
    }

    /** @test */
    public function a_period_with_no_start_left_after_the_cut_off_is_closed()
    {
        // 11:30 today: only 11:00 remains in the morning, and it is inside the
        // one-hour buffer, so the morning genuinely has nothing left.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-12-01 11:30:00'));

        $map = $this->service()->availabilityByDay($this->instructor->id, today(), 1);

        $this->assertFalse($map['2026-12-01']['morning']['available']);
        $this->assertStringContainsString('Too soon', $map['2026-12-01']['morning']['reason']);
    }

    /** @test */
    public function a_fully_booked_day_reports_no_remaining_capacity()
    {
        $date = now()->addDays(4)->format('Y-m-d');

        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');

        // Level 3 holds 5 seats; fill the morning completely.
        Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->surfSpot->id,
            'status' => BookingStatus::Confirmed,
            'date' => $date,
            'time_period' => 'morning',
            'start_time' => '06:00',
            'end_time' => '12:00',
            'duration_hours' => 6,
            'student_count' => 5,
            'student_age' => 25,
        ]);

        $map = $this->service()->availabilityByDay($this->instructor->id, now(), 10);

        $this->assertFalse($map[$date]['morning']['available']);
        $this->assertSame(0, $map[$date]['morning']['remaining_capacity']);
    }

    /** @test */
    public function the_calendar_endpoint_returns_a_days_map_and_respects_the_horizon()
    {
        $response = $this->getJson(route('api.availability.calendar', $this->instructor->id))
            ->assertOk()
            ->assertJsonStructure([
                'start',
                'days',
                'horizon_days',
                'days_map',
            ]);

        $this->assertSame(60, $response->json('days'));

        // Asking for more than the horizon is clamped to it. (The `days`
        // request field is capped at 120 by validation, so stay under that to
        // exercise the horizon clamp itself.)
        $this->getJson(route('api.availability.calendar', [
            'instructor' => $this->instructor->id,
            'days' => 100,
        ]))->assertOk()->assertJsonPath('days', 60);

        // Beyond the validation ceiling is rejected outright.
        $this->getJson(route('api.availability.calendar', [
            'instructor' => $this->instructor->id,
            'days' => 500,
        ]))->assertStatus(422);
    }

    /** @test */
    public function a_date_inside_the_horizon_is_bookable()
    {
        $date = now()->addDays(10)->format('Y-m-d');

        $reason = $this->service()->rangeBookabilityReason(
            $this->instructor->id,
            $date,
            '07:00',
            '09:00',
        );

        $this->assertNull($reason, "Expected {$date} to be bookable, got: {$reason}");
    }

    /** @test */
    public function a_date_past_the_horizon_is_rejected_by_the_service()
    {
        $date = now()->addDays(200)->format('Y-m-d');

        $reason = $this->service()->rangeBookabilityReason(
            $this->instructor->id,
            $date,
            '07:00',
            '09:00',
        );

        $this->assertNotNull($reason);
        $this->assertStringContainsString('60 days ahead', $reason);
    }

    /** @test */
    public function the_single_slot_assertion_also_enforces_the_horizon()
    {
        $reason = $this->service()->bookabilityReason(
            $this->instructor->id,
            now()->addDays(200)->format('Y-m-d'),
            'morning',
            '07:00',
        );

        $this->assertNotNull($reason);
        $this->assertStringContainsString('days ahead', $reason);
    }

    /** @test */
    public function the_horizon_is_read_from_config()
    {
        config(['siasurf.booking.horizon_days' => 14]);

        $service = $this->service();
        $this->assertSame(14, $service->bookingHorizonDays());

        // 20 days out is now beyond the horizon.
        $reason = $service->rangeBookabilityReason(
            $this->instructor->id,
            now()->addDays(20)->format('Y-m-d'),
            '07:00',
            '09:00',
        );

        $this->assertNotNull($reason);
        $this->assertStringContainsString('14 days ahead', $reason);
    }

    /** @test */
    public function the_horizon_check_does_not_mutate_the_callers_date()
    {
        $date = now()->addDays(200)->setTime(15, 30, 0);

        $this->service()->rangeBookabilityReason(
            $this->instructor->id,
            $date,
            '07:00',
            '09:00',
        );

        // The guard must not have truncated the caller's Carbon to midnight.
        $this->assertSame('15:30', $date->format('H:i'));
    }

    /** @test */
    public function a_partially_booked_morning_stays_open_on_the_student_calendar()
    {
        $l1 = User::factory()->create(['role' => 'instructor']);
        $l1->assignRole('instructor');
        $l1->instructorProfile()->create([
            'level' => InstructorLevel::Level1,
            'status' => 'active',
            'rate_per_hour' => 1000,
        ]);

        foreach (range(0, 6) as $dayOfWeek) {
            Availability::create([
                'user_id' => $l1->id,
                'day_of_week' => $dayOfWeek,
                'time_period' => 'morning',
                'is_available' => true,
                'start_time' => '06:00',
                'end_time' => '12:00',
                'slot_duration_minutes' => 60,
            ]);
            Availability::create([
                'user_id' => $l1->id,
                'day_of_week' => $dayOfWeek,
                'time_period' => 'afternoon',
                'is_available' => true,
                'start_time' => '13:00',
                'end_time' => '18:00',
                'slot_duration_minutes' => 60,
            ]);
        }

        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');

        $day = now()->addDays(4)->startOfDay();
        $dateKey = $day->format('Y-m-d');

        Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $l1->id,
            'surf_spot_id' => $this->surfSpot->id,
            'date' => $dateKey,
            'time_period' => 'morning',
            'start_time' => '07:00',
            'end_time' => '09:00',
            'duration_hours' => 2,
            'student_count' => 1,
            'student_age' => 25,
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
            'status' => BookingStatus::Reserved,
            'payment_deadline_at' => now()->addMinutes(15),
        ]);

        $calendar = $this->service()->availabilityByDay($l1->id, $day, 1, 1);
        $periods = $this->service()->periodsFor($l1->id, $day, 1);

        $this->assertTrue($calendar[$dateKey]['morning']['available']);
        $this->assertTrue($periods['morning']['available']);

        Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $l1->id,
            'surf_spot_id' => $this->surfSpot->id,
            'date' => $dateKey,
            'time_period' => 'morning',
            'start_time' => '06:00',
            'end_time' => '07:00',
            'duration_hours' => 1,
            'student_count' => 1,
            'student_age' => 25,
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
            'status' => BookingStatus::Confirmed,
        ]);

        Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $l1->id,
            'surf_spot_id' => $this->surfSpot->id,
            'date' => $dateKey,
            'time_period' => 'morning',
            'start_time' => '09:00',
            'end_time' => '12:00',
            'duration_hours' => 3,
            'student_count' => 1,
            'student_age' => 25,
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
            'status' => BookingStatus::Confirmed,
        ]);

        $fullCalendar = $this->service()->availabilityByDay($l1->id, $day, 1, 1);

        $this->assertFalse($fullCalendar[$dateKey]['morning']['available']);
    }

    /** @test */
    public function availability_by_day_releases_expired_checkout_holds()
    {
        $l1 = User::factory()->create(['role' => 'instructor']);
        $l1->assignRole('instructor');
        $l1->instructorProfile()->create([
            'level' => InstructorLevel::Level1,
            'status' => 'active',
            'rate_per_hour' => 1000,
        ]);

        foreach (range(0, 6) as $dayOfWeek) {
            Availability::create([
                'user_id' => $l1->id,
                'day_of_week' => $dayOfWeek,
                'time_period' => 'morning',
                'is_available' => true,
                'start_time' => '06:00',
                'end_time' => '12:00',
                'slot_duration_minutes' => 60,
            ]);
        }

        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');

        $day = now()->addDays(5)->startOfDay();
        $dateKey = $day->format('Y-m-d');

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $l1->id,
            'surf_spot_id' => $this->surfSpot->id,
            'date' => $dateKey,
            'time_period' => 'morning',
            'start_time' => '06:00',
            'end_time' => '12:00',
            'duration_hours' => 6,
            'student_count' => 1,
            'student_age' => 25,
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
            'status' => BookingStatus::Reserved,
            'payment_deadline_at' => now()->subMinute(),
            'accept_deadline_at' => null,
        ]);

        $calendar = $this->service()->availabilityByDay($l1->id, $day, 1, 1);

        $this->assertSame(BookingStatus::Expired, $booking->fresh()->status);
        $this->assertTrue($calendar[$dateKey]['morning']['available']);
    }
}
