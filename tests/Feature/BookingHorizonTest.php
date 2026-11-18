<?php

namespace Tests\Feature;

use App\Enums\InstructorLevel;
use App\Enums\SkillLevel;
use App\Models\Availability;
use App\Models\SurfSpot;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The booking horizon must be enforced on submission, not just in the calendar
 * UI. A hand-crafted POST is the actual attack surface.
 */
class BookingHorizonTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;
    private User $student;
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

        $this->student = User::factory()->create(['role' => 'student']);
        $this->student->assignRole('student');

        $this->surfSpot = SurfSpot::factory()->create([
            'difficulty' => 'beginner',
            'is_active' => true,
        ]);

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

    private function payload(string $date): array
    {
        return [
            'date' => $date,
            'start_time' => '07:00',
            'end_time' => '09:00',
            'duration_hours' => 2,
            'time_period' => 'morning',
            'skill_level' => 'beginner',
            'student_age' => 25,
            'student_count' => 1,
            'surf_spot_id' => $this->surfSpot->id,
            'has_board' => true,
            'notes' => '',
            'companions' => [],
        ];
    }

    private function service(): AvailabilityService
    {
        return app(AvailabilityService::class);
    }

    /** @test */
    public function a_booking_past_the_horizon_is_refused_at_submission()
    {
        $date = now()->addDays(200)->format('Y-m-d');

        $response = $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor->id), $this->payload($date));

        // The controller reports a booking conflict as a flashed error.
        $response->assertSessionHas('error');

        $message = session('error');
        $this->assertStringContainsString('60 days ahead', $message);

        $this->assertDatabaseMissing('bookings', [
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'date' => $date,
        ]);
    }

    /** @test */
    public function a_booking_inside_the_horizon_is_not_refused_for_horizon_reasons()
    {
        $date = now()->addDays(10)->format('Y-m-d');

        // Sanity: the availability layer agrees this date is fine.
        $this->assertNull(
            $this->service()->rangeBookabilityReason(
                $this->instructor->id,
                $date,
                '07:00',
                '09:00',
            )
        );

        // Whatever the controller does downstream, it must not reject on the
        // horizon message.
        $response = $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor->id), $this->payload($date));

        $error = session('error');
        $this->assertNotSame('days ahead', $error);
        $this->assertStringNotContainsString('days ahead', (string) $error);
    }

    /** @test */
    public function dropping_the_horizon_rejects_a_previously_valid_date()
    {
        $date = now()->addDays(30)->format('Y-m-d');

        config(['siasurf.booking.horizon_days' => 60]);
        $this->assertNull(
            $this->service()->rangeBookabilityReason($this->instructor->id, $date, '07:00', '09:00')
        );

        config(['siasurf.booking.horizon_days' => 7]);
        $reason = $this->service()->rangeBookabilityReason($this->instructor->id, $date, '07:00', '09:00');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('7 days ahead', $reason);
    }
}
