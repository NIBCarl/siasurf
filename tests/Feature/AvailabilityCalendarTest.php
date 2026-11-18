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
}
