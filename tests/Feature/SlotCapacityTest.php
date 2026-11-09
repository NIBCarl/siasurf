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
 * Slot and seat capacity across instructor levels:
 *
 *  - Level 1 (max 1 seat, 1-on-1): 1 student occupies 1/1 seat immediately;
 *  - Level 2 (max 2 seats): stays open for the 2nd student when only 1 seat is
 *    occupied, and closes once 2/2 seats are occupied;
 *  - Level 3 (max 5 seats): stays open for remaining seats until 5/5 students
 *    share the slot (sum of `student_count`), and closes once all 5 seats are
 *    occupied;
 *  - Seats are occupied immediately from the moment a student submits booking
 *    details and enters the waiver phase (15-minute checkout hold);
 *  - Abandoning the waiver, expiring after 15 minutes, or declining a request
 *    frees the held seat(s) immediately.
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

    private function anotherStudent(): User
    {
        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');
        $student->studentProfile()->create([
            'skill_level' => 'beginner',
            'is_first_time' => false,
            'status' => 'active',
        ]);

        return $student;
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
    public function level_two_tracks_two_seats_during_waiver_and_closes_when_both_seats_are_taken(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level2);
        $studentB = $this->anotherStudent();
        $studentC = $this->anotherStudent();

        // Student A books 1 of 2 seats and enters the Waiver page.
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
            ]))
            ->assertRedirect();

        $bookingA = Booking::where('instructor_id', $instructor->id)->firstOrFail();
        $this->assertTrue($bookingA->isInStudentCheckout());

        // 1 of 2 seats is now occupied at 07:00:
        // - Student B looking for 1 seat still sees 07:00 open (with 1 seat remaining).
        $responseForOne = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 1,
        ]))->assertOk();

        $this->assertContains('07:00', $responseForOne->json('periods.morning.slots'));
        $this->assertSame(1, $responseForOne->json('periods.morning.slot_remaining.07:00'));

        // - Student B looking for 2 seats does NOT see 07:00 open, and cannot book 2 seats.
        $slotsForTwo = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 2,
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertNotContains('07:00', $slotsForTwo);

        $this->actingAs($studentB)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 2,
            ]))
            ->assertSessionHas('error');

        // - Student B CAN book the remaining 2nd seat (student_count = 1).
        $this->actingAs($studentB)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
            ]))
            ->assertRedirect();

        // Now 2/2 seats are occupied at 07:00 -> slot is completely full for Student C.
        $slotsAfterFull = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 1,
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertNotContains('07:00', $slotsAfterFull);
        $this->assertNotContains('08:00', $slotsAfterFull);
        $this->assertContains('09:00', $slotsAfterFull);

        $this->actingAs($studentC)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
            ]))
            ->assertSessionHas('error');

        // And if Student A books all 2 seats upfront on another time (09:00-11:00),
        // 09:00 closes immediately while Student A is on the waiver page.
        $instructor2 = $this->instructor(InstructorLevel::Level2);
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor2->id), $this->payload([
                'student_count' => 2,
            ]))
            ->assertRedirect();

        $slotsWhenAllSeatsBooked = $this->getJson(route('api.availability', [
            'instructor' => $instructor2->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 1,
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertNotContains('07:00', $slotsWhenAllSeatsBooked);
    }

    /** @test */
    public function level_three_tracks_five_seats_across_multiple_bookings_and_closes_at_five(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level3);
        $studentB = $this->anotherStudent();
        $studentC = $this->anotherStudent();

        // Student A books 2 of 5 seats and enters the Waiver page.
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 2,
            ]))
            ->assertRedirect();

        // 3 seats remain at 07:00: open for 3 students, closed for 4 students.
        $responseForThree = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 3,
        ]))->assertOk();

        $this->assertContains('07:00', $responseForThree->json('periods.morning.slots'));
        $this->assertSame(3, $responseForThree->json('periods.morning.slot_remaining.07:00'));

        $slotsForFour = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 4,
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertNotContains('07:00', $slotsForFour);

        // Student B books the remaining 3 seats -> 5/5 seats are now occupied.
        $this->actingAs($studentB)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 3,
            ]))
            ->assertRedirect();

        // Slot 07:00 is now completely full for Student C.
        $slotsAfterFive = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 1,
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertNotContains('07:00', $slotsAfterFive);
        $this->assertContains('09:00', $slotsAfterFive);

        $this->actingAs($studentC)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
            ]))
            ->assertSessionHas('error');
    }

    /** @test */
    public function abandoning_waiver_or_going_back_immediately_releases_the_held_seats(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level2);

        // Student A books all 2 seats of a Level 2 instructor and enters the Waiver page.
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 2,
            ]))
            ->assertRedirect();

        $booking = Booking::where('instructor_id', $instructor->id)->firstOrFail();

        // Slot 07:00 is currently full while Student A is on the Waiver page.
        $slotsWhileHeld = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
        ]))->assertOk()->json('periods.morning.slots');
        $this->assertNotContains('07:00', $slotsWhileHeld);

        // Student A clicks "Change Slot / Release Hold" on the Waiver page.
        $this->actingAs($this->student)
            ->post(route('bookings.abandon', $booking->id))
            ->assertRedirect(route('bookings.create', $instructor->id));

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);

        // Both seats at 07:00 are immediately open again.
        $responseAfterAbandon = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
        ]))->assertOk();

        $this->assertContains('07:00', $responseAfterAbandon->json('periods.morning.slots'));
        $this->assertSame(2, $responseAfterAbandon->json('periods.morning.slot_remaining.07:00'));
    }

    /** @test */
    public function expired_fifteen_minute_waiver_hold_automatically_releases_the_held_seats(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level3);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 5,
            ]))
            ->assertRedirect();

        $booking = Booking::where('instructor_id', $instructor->id)->firstOrFail();

        // Simulate the 15-minute checkout hold expiring while Student A is idle.
        $booking->update(['payment_deadline_at' => now()->subMinute()]);

        // Next availability lookup automatically expires the stale hold and frees all 5 seats at 07:00.
        $response = $this->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
        ]))->assertOk();

        $this->assertContains('07:00', $response->json('periods.morning.slots'));
        $this->assertSame(5, $response->json('periods.morning.slot_remaining.07:00'));
        $this->assertSame(BookingStatus::Expired, $booking->fresh()->status);
    }

    /** @test */
    public function instructor_only_sees_the_booking_after_student_completes_waiver_and_payment_selection(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $instructor = $this->instructor(InstructorLevel::Level2);

        // Step 1: Student A submits details and lands on the Waiver page.
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 2,
            ]))
            ->assertRedirect();

        $booking = Booking::where('instructor_id', $instructor->id)->firstOrFail();

        // While Student A is still signing the waiver, the instructor's pending count is 0.
        $this->actingAs($instructor)
            ->get(route('instructor.bookings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('counts.pending', 0));

        // Step 2: Student A signs the waiver and selects a payment method.
        $participant = $booking->participants()->first();
        $this->actingAs($this->student)
            ->post(route('bookings.store-waiver', $booking->id), [
                'signed_by' => 'Student A',
                'signature' => 'data:image/png;base64,AAAA',
                'agreement' => true,
                'participant_id' => $participant?->id,
            ]);

        $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking->id), [
                'payment_method' => 'cash',
            ])
            ->assertRedirect(route('bookings.confirmation', $booking->id));

        // Now the instructor sees the pending booking request.
        $this->actingAs($instructor)
            ->get(route('instructor.bookings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('counts.pending', 1));
    }

    /** @test */
    public function per_booking_student_limits_are_enforced_by_instructor_level(): void
    {
        $levelOne = $this->instructor(InstructorLevel::Level1);
        $levelTwo = $this->instructor(InstructorLevel::Level2);
        $levelThree = $this->instructor(InstructorLevel::Level3);

        // Level 1 allows 1 student (1-on-1), rejects 2 students.
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $levelOne->id), $this->payload(['student_count' => 2]))
            ->assertSessionHas('error');

        // Level 2 allows 2 students, rejects 3 students.
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $levelTwo->id), $this->payload(['student_count' => 3]))
            ->assertSessionHas('error');

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $levelTwo->id), $this->payload(['student_count' => 2]))
            ->assertRedirect();

        // Level 3 allows 5 students, rejects 6 students.
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $levelThree->id), $this->payload(['student_count' => 6]))
            ->assertSessionHas('error');

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $levelThree->id), $this->payload(['student_count' => 5]))
            ->assertRedirect();
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
}
