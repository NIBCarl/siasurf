<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\InstructorLevel;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $instructor;
    private SurfSpot $surfSpot;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        $this->student = User::factory()->create(['role' => 'student']);
        $this->student->assignRole('student');

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 1000,
        ]);

        $this->surfSpot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
    }

    /** @test */
    public function instructor_can_save_weekly_availability()
    {
        $payload = [
            'availabilities' => [
                ['day_of_week' => 1, 'time_period' => 'morning', 'is_available' => 1],
                ['day_of_week' => 1, 'time_period' => 'afternoon', 'is_available' => 0],
                ['day_of_week' => 3, 'time_period' => 'morning', 'is_available' => 1],
            ],
        ];

        $this->actingAs($this->instructor)
            ->post(route('instructor.availability.weekly'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('availabilities', [
            'user_id' => $this->instructor->id,
            'day_of_week' => 1,
            'time_period' => 'morning',
            'is_available' => true,
            'specific_date' => null,
        ]);

        $this->assertDatabaseHas('availabilities', [
            'user_id' => $this->instructor->id,
            'day_of_week' => 3,
            'time_period' => 'morning',
            'is_available' => true,
            'specific_date' => null,
        ]);

        $this->assertDatabaseHas('availabilities', [
            'user_id' => $this->instructor->id,
            'day_of_week' => 1,
            'time_period' => 'afternoon',
            'is_available' => false,
            'specific_date' => null,
        ]);
    }

    /** @test */
    public function saving_weekly_availability_is_idempotent()
    {
        $payload = [
            'availabilities' => [
                ['day_of_week' => 5, 'time_period' => 'morning', 'is_available' => 1],
            ],
        ];

        $this->actingAs($this->instructor)
            ->post(route('instructor.availability.weekly'), $payload);

        $this->actingAs($this->instructor)
            ->post(route('instructor.availability.weekly'), $payload);

        $this->assertSame(
            1,
            Availability::where('user_id', $this->instructor->id)
                ->where('day_of_week', 5)
                ->where('time_period', 'morning')
                ->count()
        );
    }

    /** @test */
    public function the_availability_page_exposes_the_saved_slots()
    {
        Availability::create([
            'user_id' => $this->instructor->id,
            'day_of_week' => 2,
            'time_period' => 'morning',
            'is_available' => true,
            'specific_date' => null,
        ]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.availability.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Instructor/Availability/Index')
                ->has('availabilities')
                ->has('daysOfWeek', 7)
                ->has('timePeriods', 2)
            );
    }
}