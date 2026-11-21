<?php

namespace Tests\Feature;

use App\Enums\InstructorLevel;
use App\Enums\SessionType;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceBasedPricingAndCapacityTest extends TestCase
{
    use RefreshDatabase;

    private SurfSpot $beginnerSpot;
    private SurfSpot $intermediateSpot;
    private SurfSpot $advancedSpot;
    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');

        $this->beginnerSpot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
        $this->intermediateSpot = SurfSpot::factory()->create(['difficulty' => 'intermediate']);
        $this->advancedSpot = SurfSpot::factory()->create(['difficulty' => 'advanced']);
        $this->day = now()->addDays(4)->startOfDay();
    }

    private function createStudent(string $skill = 'beginner'): User
    {
        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');
        $student->studentProfile()->create([
            'skill_level' => $skill,
            'is_first_time' => $skill === 'beginner',
            'status' => 'active',
        ]);

        return $student;
    }

    private function createInstructor(InstructorLevel $level, array $rates = []): User
    {
        $instructor = User::factory()->create(['role' => 'instructor']);
        $instructor->assignRole('instructor');
        $instructor->instructorProfile()->create(array_merge([
            'level' => $level,
            'status' => 'active',
            'rate_per_hour' => 700,
            'guiding_rate' => $level->value >= 2 ? 1200 : null,
            'coaching_rate' => $level->value >= 3 ? 1800 : null,
            'auto_accept_bookings' => false,
        ], $rates));

        Availability::create([
            'user_id' => $instructor->id,
            'day_of_week' => (int) $this->day->format('w'),
            'time_period' => 'morning',
            'is_available' => true,
            'start_time' => '06:00',
            'end_time' => '12:00',
            'slot_duration_minutes' => 60,
            'specific_date' => null,
        ]);

        return $instructor;
    }

    private function bookingPayload(SurfSpot $spot, array $overrides = []): array
    {
        return array_merge([
            'date' => $this->day->format('Y-m-d'),
            'start_time' => '07:00',
            'end_time' => '09:00',
            'student_age' => 24,
            'height' => 170,
            'weight' => 65,
            'student_count' => 1,
            'has_board' => false,
            'surf_spot_id' => $spot->id,
        ], $overrides);
    }

    public function test_beginner_booking_level_3_instructor_for_teaching_pays_teaching_rate_not_coaching_rate(): void
    {
        $instructor = $this->createInstructor(InstructorLevel::Level3, [
            'rate_per_hour' => 750,
            'guiding_rate' => 1200,
            'coaching_rate' => 1850,
        ]);
        $beginner = $this->createStudent('beginner');

        $this->actingAs($beginner)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->beginnerSpot, [
                'session_type' => 'teaching',
                'start_time' => '07:00',
                'end_time' => '09:00',
            ]))
            ->assertRedirect();

        $booking = Booking::firstOrFail();

        $this->assertSame(SessionType::Teaching, $booking->session_type);
        $this->assertSame('750.00', $booking->hourly_rate);
        $this->assertSame('1500.00', $booking->total_amount); // 750 * 2 hrs
    }

    public function test_child_aged_5_to_12_can_book_solo_level_3_teaching_at_teaching_rate(): void
    {
        $instructor = $this->createInstructor(InstructorLevel::Level3, [
            'rate_per_hour' => 650,
            'coaching_rate' => 1900,
        ]);
        $child = $this->createStudent('beginner');

        $this->actingAs($child)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->beginnerSpot, [
                'student_age' => 9,
                'session_type' => 'teaching',
                'start_time' => '07:00',
                'end_time' => '08:00',
            ]))
            ->assertRedirect();

        $booking = Booking::firstOrFail();

        $this->assertSame(SessionType::Teaching, $booking->session_type);
        $this->assertSame('650.00', $booking->hourly_rate);
        $this->assertSame('650.00', $booking->total_amount); // 1 hour Surf Experience
    }

    public function test_instructor_can_edit_guiding_rate_within_800_to_1500_and_bookings_use_that_exact_rate(): void
    {
        $instructor = $this->createInstructor(InstructorLevel::Level2, [
            'rate_per_hour' => 700,
            'guiding_rate' => 900,
        ]);

        // Out-of-range guiding rates are rejected.
        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'rate_per_hour' => 700,
                'guiding_rate' => 750,
            ])
            ->assertSessionHasErrors('guiding_rate');

        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'rate_per_hour' => 700,
                'guiding_rate' => 1600,
            ])
            ->assertSessionHasErrors('guiding_rate');

        // Within-range guiding rate (₱1,250) becomes the instructor's final guiding rate.
        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'rate_per_hour' => 700,
                'guiding_rate' => 1250,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('1250.00', $instructor->instructorProfile->fresh()->guiding_rate);

        // Intermediate student booking Guiding pays ₱1,250/hr × 2 hrs = ₱2,500.
        $student = $this->createStudent('intermediate');
        $this->actingAs($student)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->intermediateSpot, [
                'session_type' => 'guiding',
                'start_time' => '07:00',
                'end_time' => '09:00',
            ]))
            ->assertRedirect();

        $booking = Booking::firstOrFail();
        $this->assertSame(SessionType::Guiding, $booking->session_type);
        $this->assertSame('1250.00', $booking->hourly_rate);
        $this->assertSame('2500.00', $booking->total_amount);
    }

    public function test_level_3_instructor_can_edit_coaching_rate_within_1500_to_2000_only(): void
    {
        $instructor = $this->createInstructor(InstructorLevel::Level3, [
            'rate_per_hour' => 700,
            'guiding_rate' => 1100,
            'coaching_rate' => 1500,
        ]);

        // Below 1500 or above 2000 rejected.
        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'rate_per_hour' => 700,
                'guiding_rate' => 1100,
                'coaching_rate' => 1400,
            ])
            ->assertSessionHasErrors('coaching_rate');

        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'rate_per_hour' => 700,
                'guiding_rate' => 1100,
                'coaching_rate' => 2100,
            ])
            ->assertSessionHasErrors('coaching_rate');

        // Within range (₱1,850) accepted and applied to Coaching bookings.
        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'rate_per_hour' => 700,
                'guiding_rate' => 1100,
                'coaching_rate' => 1850,
            ])
            ->assertSessionHasNoErrors();

        $advanced = $this->createStudent('advanced');
        $this->actingAs($advanced)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->advancedSpot, [
                'session_type' => 'coaching',
                'start_time' => '07:00',
                'end_time' => '09:00',
            ]))
            ->assertRedirect();

        $booking = Booking::firstOrFail();
        $this->assertSame(SessionType::Coaching, $booking->session_type);
        $this->assertSame('1850.00', $booking->hourly_rate);
        $this->assertSame('3700.00', $booking->total_amount);
    }

    public function test_level_3_allows_three_separate_same_level_students_to_share_a_slot_and_blocks_different_level_or_service(): void
    {
        $instructor = $this->createInstructor(InstructorLevel::Level3);
        $beginner1 = $this->createStudent('beginner');
        $beginner2 = $this->createStudent('beginner');
        $beginner3 = $this->createStudent('beginner');
        $beginner4 = $this->createStudent('beginner');
        $intermediate = $this->createStudent('intermediate');

        // Beginner 1 books Teaching at 07:00-09:00 (1/3 seats).
        $this->actingAs($beginner1)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->beginnerSpot, [
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        // Intermediate student CANNOT join the same 07:00-09:00 slot (different skill level).
        $this->actingAs($intermediate)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->intermediateSpot, [
                'session_type' => 'guiding',
            ]))
            ->assertSessionHas('error');

        // Another Beginner booking COACHING CANNOT join the Teaching slot (different service).
        $this->actingAs($beginner2)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->beginnerSpot, [
                'session_type' => 'coaching',
            ]))
            ->assertSessionHas('error');

        // Beginner 2 & Beginner 3 CAN share the remaining 2 seats for Beginner Teaching.
        $this->actingAs($beginner2)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->beginnerSpot, [
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        $this->actingAs($beginner3)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->beginnerSpot, [
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        // 4th Beginner is rejected (3/3 full).
        $this->actingAs($beginner4)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->beginnerSpot, [
                'session_type' => 'teaching',
            ]))
            ->assertSessionHas('error');

        $this->assertSame(3, Booking::where('instructor_id', $instructor->id)->count());
    }

    public function test_mixed_skill_level_companions_in_the_same_booking_are_rejected(): void
    {
        $instructor = $this->createInstructor(InstructorLevel::Level3);
        $beginner = $this->createStudent('beginner');

        $this->actingAs($beginner)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->beginnerSpot, [
                'student_count' => 2,
                'session_type' => 'teaching',
                'participants' => [
                    [
                        'full_name' => $beginner->name,
                        'age' => 24,
                        'height' => 170,
                        'weight' => 65,
                        'skill_level' => 'beginner',
                        'has_board' => false,
                    ],
                    [
                        'full_name' => 'Advanced Friend',
                        'age' => 26,
                        'height' => 175,
                        'weight' => 70,
                        'skill_level' => 'advanced',
                        'has_board' => false,
                    ],
                ],
            ]))
            ->assertSessionHas('error');

        $this->assertSame(0, Booking::count());
    }

    public function test_level_2_beginner_booking_locks_slot_at_1_seat_so_second_beginner_cannot_join(): void
    {
        $instructor = $this->createInstructor(InstructorLevel::Level2);
        $beginner1 = $this->createStudent('beginner');
        $beginner2 = $this->createStudent('beginner');

        $this->actingAs($beginner1)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->beginnerSpot, [
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        $this->actingAs($beginner2)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->beginnerSpot, [
                'session_type' => 'teaching',
            ]))
            ->assertSessionHas('error');

        $this->assertSame(1, Booking::where('instructor_id', $instructor->id)->count());
    }
}
