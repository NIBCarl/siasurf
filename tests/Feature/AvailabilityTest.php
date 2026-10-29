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
    public function custom_working_hours_and_open_slots_drive_the_public_slots(): void
    {
        $date = now()->next('Monday')->addWeek();
        $day = (int) $date->format('w');

        $this->actingAs($this->instructor)
            ->post(route('instructor.availability.weekly'), [
                'availabilities' => [[
                    'day_of_week' => $day,
                    'time_period' => 'morning',
                    'is_available' => 1,
                    'start_time' => '07:00',
                    'end_time' => '10:00',
                    'open_slots' => ['07:00', '09:00'],
                    'slot_duration_minutes' => 60,
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $availability = Availability::where('user_id', $this->instructor->id)
            ->where('day_of_week', $day)
            ->where('time_period', 'morning')
            ->firstOrFail();

        $this->assertSame('07:00', $availability->start_time->format('H:i'));
        $this->assertSame('10:00', $availability->end_time->format('H:i'));
        $this->assertSame(['07:00', '09:00'], $availability->open_slots);

        $this->getJson(route('api.availability', [
            'instructor' => $this->instructor->id,
            'date' => $date->format('Y-m-d'),
        ]))
            ->assertOk()
            ->assertJsonPath('periods.morning.slots', ['07:00', '09:00']);
    }

    /** @test */
    public function custom_working_hours_must_be_inside_the_period_and_in_order(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.availability.weekly'), [
                'availabilities' => [[
                    'day_of_week' => 1,
                    'time_period' => 'morning',
                    'is_available' => 1,
                    'start_time' => '11:00',
                    'end_time' => '10:00',
                    'open_slots' => ['11:00'],
                ]],
            ])
            ->assertSessionHasErrors('availabilities.0.end_time');

        $this->actingAs($this->instructor)
            ->post(route('instructor.availability.weekly'), [
                'availabilities' => [[
                    'day_of_week' => 1,
                    'time_period' => 'morning',
                    'is_available' => 1,
                    'start_time' => '05:00',
                    'end_time' => '09:00',
                    'open_slots' => ['05:00'],
                ]],
            ])
            ->assertSessionHasErrors('availabilities.0.start_time');
    }

    /** @test */
    public function an_open_period_must_keep_at_least_one_slot_open(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.availability.weekly'), [
                'availabilities' => [[
                    'day_of_week' => 1,
                    'time_period' => 'morning',
                    'is_available' => 1,
                    'start_time' => '07:00',
                    'end_time' => '10:00',
                    'open_slots' => [],
                ]],
            ])
            ->assertSessionHasErrors('availabilities.0.open_slots');
    }

    /** @test */
    public function a_confirmed_lesson_only_removes_its_overlapping_slot(): void
    {
        $date = now()->next('Monday')->addWeek();
        $day = (int) $date->format('w');

        $this->actingAs($this->instructor)
            ->post(route('instructor.availability.weekly'), [
                'availabilities' => [[
                    'day_of_week' => $day,
                    'time_period' => 'morning',
                    'is_available' => 1,
                    'start_time' => '07:00',
                    'end_time' => '11:00',
                    'open_slots' => ['07:00', '08:00', '09:00', '10:00'],
                    // Pin a 1-hour lesson so this tests the flexible schedule's
                    // per-slot behaviour, not the platform 2-hour default.
                    'slot_duration_minutes' => 60,
                ]],
            ])
            ->assertSessionHasNoErrors();

        Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->surfSpot->id,
            'date' => $date->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '07:00',
            'end_time' => '08:00',
            'duration_hours' => 1,
            'status' => BookingStatus::Confirmed,
        ]);

        $this->getJson(route('api.availability', [
            'instructor' => $this->instructor->id,
            'date' => $date->format('Y-m-d'),
        ]))
            ->assertOk()
            ->assertJsonPath('periods.morning.available', true)
            ->assertJsonPath('periods.morning.slots', ['08:00', '09:00', '10:00']);
    }

    /** @test */
    public function the_availability_page_exposes_the_saved_slots(): void
    {
        Availability::create([
            'user_id' => $this->instructor->id,
            'day_of_week' => 2,
            'time_period' => 'morning',
            'is_available' => true,
            'start_time' => '07:00',
            'end_time' => '10:00',
            'open_slots' => ['07:00', '09:00'],
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