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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Slot capacity is the core of the availability rewrite:
 *
 *  - a Level 1 request owns the hour from the moment it is submitted;
 *  - Level 2 is full at one student;
 *  - Level 3 stays open until five students share the slot, and the counter is
 *    the SUM of `student_count`, not the number of bookings;
 *  - declining a request hands the range (or the seats) back immediately.
 */
class SlotCapacityTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private SurfSpot $spot;
    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();

        // The tide lookup must never reach the network in tests.
        Http::fake();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        $this->student = User::factory()->create(['role' => 'student']);
        $this->student->assignRole('student');
        $this->student->studentProfile()->create([
            'skill_level' => 'beginner',
            'is_first_time' => false,
            'status' => 'active',
        ]);

        $this->spot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
        $this->day = now()->addDays(3)->startOfDay();
    }

    private function instructor(InstructorLevel $level): User
    {
        $instructor = User::factory()->create(['role' => 'instructor']);
        $instructor->assignRole('instructor');
        $instructor->instructorProfile()->create([
            'level' => $level,
            'status' => 'active',
            'rate_per_hour' => 1000,
            'auto_accept_bookings' => false,
        ]);

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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'date' => $this->day->format('Y-m-d'),
            'start_time' => '07:00',
            'end_time' => '09:00',
            'student_age' => 25,
            'height' => 170,
            'weight' => 65,
            'student_count' => 1,
            'has_board' => false,
            'surf_spot_id' => $this->spot->id,
        ], $overrides);
    }

    private function bookingFor(User $instructor, array $attributes = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'student_id' => $this->student->id,
            'instructor_id' => $instructor->id,
            'surf_spot_id' => $this->spot->id,
            'date' => $this->day->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '07:00',
            'end_time' => '09:00',
            'duration_hours' => 2,
            'student_count' => 1,
            'status' => BookingStatus::Confirmed,
        ], $attributes));
    }

    /** @test */
    public function a_pending_level_one_request_immediately_blocks_only_its_own_hours(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level1);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload())
            ->assertRedirect();

        $booking = Booking::where('instructor_id', $instructor->id)->firstOrFail();

        $this->assertSame(BookingStatus::Pending, $booking->status);

        // The system lock exists before the instructor has answered.
        $this->assertDatabaseHas('availabilities', [
            'user_id' => $instructor->id,
            'time_period' => 'morning',
            'is_available' => false,
            'blocked_by_booking_id' => $booking->id,
        ]);

        $slots = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
        ]))->assertOk()->json('periods.morning.slots');

        // 07:00 and 08:00 overlap the pending lesson; 09:00 and later stay open.
        $this->assertNotContains('07:00', $slots);
        $this->assertNotContains('08:00', $slots);
        $this->assertContains('09:00', $slots);
        $this->assertContains('10:00', $slots);
    }

    /** @test */
    public function declining_a_request_frees_the_slot_again(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level1);

        $booking = $this->bookingFor($instructor, ['status' => BookingStatus::Pending]);

        Availability::create([
            'user_id' => $instructor->id,
            'day_of_week' => (int) $this->day->format('w'),
            'time_period' => 'morning',
            'is_available' => false,
            'specific_date' => $this->day->format('Y-m-d'),
            'blocked_by_booking_id' => $booking->id,
        ]);

        $this->actingAs($instructor)
            ->post(route('instructor.bookings.decline', $booking->id), [
                'reason' => 'Already booked that morning.',
            ])
            ->assertRedirect();

        $this->assertSame(BookingStatus::Declined, $booking->fresh()->status);
        $this->assertSame('Already booked that morning.', $booking->fresh()->decline_reason);

        $this->assertDatabaseMissing('availabilities', [
            'user_id' => $instructor->id,
            'specific_date' => $this->day->format('Y-m-d'),
            'blocked_by_booking_id' => $booking->id,
        ]);

        $slots = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertContains('07:00', $slots);
        $this->assertContains('08:00', $slots);
    }

    /** @test */
    public function a_level_three_slot_counts_students_and_closes_only_at_capacity(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level3);
        $availability = app(AvailabilityService::class);

        $this->bookingFor($instructor, ['student_count' => 2]);

        // Two of five seats are taken: the slot is still offered.
        $slots = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 1,
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertContains('07:00', $slots);
        $this->assertSame(2, $availability->bookedStudentsInRange(
            $instructor->id,
            $this->day->format('Y-m-d'),
            '07:00',
            '09:00',
        ));
        $this->assertSame(3, $availability->remainingCapacity(
            $instructor->id,
            $this->day->format('Y-m-d'),
            '07:00',
            '09:00',
        ));

        // A second booking fills the remaining three seats.
        $this->bookingFor($instructor, ['student_count' => 3]);

        $this->assertSame(5, $availability->bookedStudentsInRange(
            $instructor->id,
            $this->day->format('Y-m-d'),
            '07:00',
            '09:00',
        ));
        $this->assertSame(0, $availability->remainingCapacity(
            $instructor->id,
            $this->day->format('Y-m-d'),
            '07:00',
            '09:00',
        ));

        $slots = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 1,
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertNotContains('07:00', $slots);
        $this->assertNotContains('08:00', $slots);
    }

    /** @test */
    public function a_level_three_request_is_rejected_when_the_party_does_not_fit(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level3);

        // Four of five seats already booked.
        $this->bookingFor($instructor, ['student_count' => 4]);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 2,
            ]))
            ->assertSessionHas('error');

        $this->assertSame(1, Booking::count());
    }

    /** @test */
    public function a_level_two_instructor_is_one_on_one(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level2);

        $this->bookingFor($instructor, ['student_count' => 1]);

        $slots = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertNotContains('07:00', $slots);
        $this->assertContains('09:00', $slots);
    }

    /** @test */
    public function the_selected_end_time_is_stored_and_sets_the_duration(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level1);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'start_time' => '07:00',
                'end_time' => '08:00',
            ]))
            ->assertRedirect();

        $booking = Booking::firstOrFail();

        $this->assertSame('08:00', $booking->end_time->format('H:i'));
        $this->assertSame(1, $booking->duration_hours);
    }

    /** @test */
    public function the_end_time_must_be_after_the_start_time(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level1);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'start_time' => '09:00',
                'end_time' => '08:00',
            ]))
            ->assertSessionHasErrors('end_time');

        $this->assertSame(0, Booking::count());
    }

    /** @test */
    public function height_and_weight_are_optional_when_the_student_brings_a_board(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level1);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'has_board' => true,
                'height' => null,
                'weight' => null,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $booking = Booking::firstOrFail();

        $this->assertTrue($booking->has_board);
        $this->assertNull($booking->height);
        $this->assertNull($booking->weight);
    }

    /** @test */
    public function height_and_weight_are_required_when_the_student_needs_a_board(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level1);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'has_board' => false,
                'height' => null,
                'weight' => null,
            ]))
            ->assertSessionHasErrors(['height', 'weight']);

        $this->assertSame(0, Booking::count());
    }

    /** @test */
    public function a_level_three_instructor_accepts_several_bookings_until_the_slot_is_full(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level3);
        $availability = app(AvailabilityService::class);
        $date = $this->day->format('Y-m-d');

        // Three separate one-student bookings share the same hours.
        $this->bookingFor($instructor, ['student_count' => 1]);
        $this->bookingFor($instructor, ['student_count' => 1]);
        $this->bookingFor($instructor, ['student_count' => 1]);

        $this->assertSame(3, $availability->bookedStudentsInRange($instructor->id, $date, '07:00', '09:00'));
        $this->assertSame(2, $availability->remainingCapacity($instructor->id, $date, '07:00', '09:00'));

        $slotsForOne = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $date,
            'students' => 1,
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertContains('07:00', $slotsForOne);

        // A party of two still fits into the two remaining seats...
        $slotsForTwo = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $date,
            'students' => 2,
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertContains('07:00', $slotsForTwo);

        // ...but a party of three does not.
        $slotsForThree = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $date,
            'students' => 3,
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertNotContains('07:00', $slotsForThree);
    }

    /** @test */
    public function a_level_three_request_can_top_up_an_existing_slot(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level3);

        // Three of the five seats are already taken.
        $this->bookingFor($instructor, ['student_count' => 3]);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 2,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(2, Booking::count());
        $this->assertSame(
            5,
            app(AvailabilityService::class)->bookedStudentsInRange(
                $instructor->id,
                $this->day->format('Y-m-d'),
                '07:00',
                '09:00',
            )
        );
    }
}

