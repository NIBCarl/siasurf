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

    private function anotherStudent(string $skill = 'beginner'): User
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

    private function payload(array $overrides = []): array
    {
        $payload = array_merge([
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

        $count = (int) ($payload['student_count'] ?? 1);
        if ($count > 1 && ! array_key_exists('participants', $overrides)) {
            $skill = $payload['skill_level'] ?? match ($payload['session_type'] ?? 'teaching') {
                'guiding' => 'intermediate',
                'coaching' => 'advanced',
                default => 'beginner',
            };
            $participants = [];
            for ($i = 0; $i < $count; $i++) {
                $participants[] = [
                    'full_name' => 'Surfer '.($i + 1),
                    'age' => $payload['student_age'],
                    'height' => $payload['height'],
                    'weight' => $payload['weight'],
                    'skill_level' => $skill,
                    'has_board' => (bool) $payload['has_board'],
                ];
            }
            $payload['participants'] = $participants;
        }

        return $payload;
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

        $this->assertSame(BookingStatus::Reserved, $booking->status);

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
        $studentA = $this->anotherStudent('intermediate');
        $studentB = $this->anotherStudent('intermediate');
        $studentC = $this->anotherStudent('intermediate');

        // Student A (Intermediate) books 1 of 2 seats (Guiding) and enters the Waiver page.
        $this->actingAs($studentA)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'guiding',
            ]))
            ->assertRedirect();

        $bookingA = Booking::where('instructor_id', $instructor->id)->firstOrFail();
        $this->assertTrue($bookingA->isInStudentCheckout());

        // 1 of 2 seats is now occupied at 07:00 for Intermediate Guiding:
        // - Student B looking for 1 seat still sees 07:00 open (with 1 seat remaining).
        $responseForOne = $this->actingAs($studentB)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 1,
            'skill_level' => 'intermediate',
            'session_type' => 'guiding',
        ]))->assertOk();

        $this->assertContains('07:00', $responseForOne->json('periods.morning.slots'));
        $this->assertSame(1, $responseForOne->json('periods.morning.slot_remaining.07:00'));

        // - Single booking requesting 2 seats is rejected (1 account = 1 seat).
        $this->actingAs($studentB)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 2,
                'session_type' => 'guiding',
            ]))
            ->assertSessionHasErrors('student_count');

        // - Student B CAN book the remaining 2nd seat (student_count = 1).
        $this->actingAs($studentB)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'guiding',
            ]))
            ->assertRedirect();

        // Now 2/2 seats are occupied at 07:00 -> slot is completely full for Student C.
        $slotsAfterFull = $this->actingAs($studentC)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 1,
            'skill_level' => 'intermediate',
            'session_type' => 'guiding',
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertNotContains('07:00', $slotsAfterFull);
        $this->assertNotContains('08:00', $slotsAfterFull);
        $this->assertContains('09:00', $slotsAfterFull);

        $this->actingAs($studentC)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'guiding',
            ]))
            ->assertSessionHas('error');

        // And when Student A and Student B each book 1 seat on another Level 2 instructor,
        // 07:00 closes immediately while both are in checkout.
        $instructor2 = $this->instructor(InstructorLevel::Level2);
        $this->actingAs($studentA)
            ->post(route('bookings.store-details', $instructor2->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'guiding',
            ]))
            ->assertRedirect();

        $this->actingAs($studentB)
            ->post(route('bookings.store-details', $instructor2->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'guiding',
            ]))
            ->assertRedirect();

        $slotsWhenAllSeatsBooked = $this->actingAs($studentC)->getJson(route('api.availability', [
            'instructor' => $instructor2->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 1,
            'skill_level' => 'intermediate',
            'session_type' => 'guiding',
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertNotContains('07:00', $slotsWhenAllSeatsBooked);
    }

    /** @test */
    public function level_three_tracks_three_seats_across_multiple_bookings_and_closes_at_three(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level3);
        $studentB = $this->anotherStudent('beginner');
        $studentC = $this->anotherStudent('beginner');
        $studentD = $this->anotherStudent('beginner');

        // Student A books 1 of 3 seats and enters the Waiver page.
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        // 2 seats remain at 07:00.
        $responseAfterOne = $this->actingAs($studentB)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 1,
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
        ]))->assertOk();

        $this->assertContains('07:00', $responseAfterOne->json('periods.morning.slots'));
        $this->assertSame(2, $responseAfterOne->json('periods.morning.slot_remaining.07:00'));

        // Student B books the 2nd seat -> 1 seat remains at 07:00.
        $this->actingAs($studentB)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        $responseAfterTwo = $this->actingAs($studentC)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 1,
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
        ]))->assertOk();

        $this->assertContains('07:00', $responseAfterTwo->json('periods.morning.slots'));
        $this->assertSame(1, $responseAfterTwo->json('periods.morning.slot_remaining.07:00'));

        // Student C books the remaining 3rd seat -> 3/3 seats are now occupied.
        $this->actingAs($studentC)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        // Slot 07:00 is now completely full for Student D.
        $slotsAfterThree = $this->actingAs($studentD)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 1,
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertNotContains('07:00', $slotsAfterThree);
        $this->assertContains('09:00', $slotsAfterThree);

        $this->actingAs($studentD)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'teaching',
            ]))
            ->assertSessionHas('error');
    }

    /** @test */
    public function abandoning_waiver_or_going_back_immediately_releases_the_held_seats(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level2);
        $intermediate1 = $this->anotherStudent('intermediate');
        $intermediate2 = $this->anotherStudent('intermediate');
        $intermediate3 = $this->anotherStudent('intermediate');

        // Two intermediate students each book 1 seat on a Level 2 instructor (2/2 seats held).
        $this->actingAs($intermediate1)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'guiding',
            ]))
            ->assertRedirect();

        $this->actingAs($intermediate2)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'guiding',
            ]))
            ->assertRedirect();

        $booking1 = Booking::where('instructor_id', $instructor->id)
            ->where('student_id', $intermediate1->id)
            ->firstOrFail();
        $booking2 = Booking::where('instructor_id', $instructor->id)
            ->where('student_id', $intermediate2->id)
            ->firstOrFail();

        // Slot 07:00 is currently full while both are in the Waiver step.
        $slotsWhileHeld = $this->actingAs($intermediate3)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'skill_level' => 'intermediate',
            'session_type' => 'guiding',
        ]))->assertOk()->json('periods.morning.slots');
        $this->assertNotContains('07:00', $slotsWhileHeld);

        // Student 1 clicks "Change Slot / Release Hold" -> 1 seat immediately reopens.
        $this->actingAs($intermediate1)
            ->post(route('bookings.abandon', $booking1->id))
            ->assertRedirect(route('bookings.create', $instructor->id));

        $this->assertSame(BookingStatus::Cancelled, $booking1->fresh()->status);

        $responseAfterFirstAbandon = $this->actingAs($intermediate3)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'skill_level' => 'intermediate',
            'session_type' => 'guiding',
        ]))->assertOk();

        $this->assertContains('07:00', $responseAfterFirstAbandon->json('periods.morning.slots'));
        $this->assertSame(1, $responseAfterFirstAbandon->json('periods.morning.slot_remaining.07:00'));

        // Student 2 also abandons -> both seats at 07:00 are open again.
        $this->actingAs($intermediate2)
            ->post(route('bookings.abandon', $booking2->id))
            ->assertRedirect(route('bookings.create', $instructor->id));

        $responseAfterBothAbandon = $this->actingAs($intermediate3)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'skill_level' => 'intermediate',
            'session_type' => 'guiding',
        ]))->assertOk();

        $this->assertSame(2, $responseAfterBothAbandon->json('periods.morning.slot_remaining.07:00'));
    }

    /** @test */
    public function expired_fifteen_minute_waiver_hold_automatically_releases_the_held_seats(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level3);
        $b2 = $this->anotherStudent('beginner');
        $b3 = $this->anotherStudent('beginner');

        foreach ([$this->student, $b2, $b3] as $student) {
            $this->actingAs($student)
                ->post(route('bookings.store-details', $instructor->id), $this->payload([
                    'student_count' => 1,
                    'session_type' => 'teaching',
                ]))
                ->assertRedirect();
        }

        $bookings = Booking::where('instructor_id', $instructor->id)->get();
        $this->assertCount(3, $bookings);

        // Simulate the 15-minute checkout hold expiring while all 3 students are idle.
        Booking::where('instructor_id', $instructor->id)->update(['payment_deadline_at' => now()->subMinute()]);

        // Next availability lookup automatically expires the stale holds and frees all 3 seats at 07:00.
        $response = $this->actingAs($this->student)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
        ]))->assertOk();

        $this->assertContains('07:00', $response->json('periods.morning.slots'));
        $this->assertSame(3, $response->json('periods.morning.slot_remaining.07:00'));
        foreach ($bookings as $booking) {
            $this->assertSame(BookingStatus::Expired, $booking->fresh()->status);
        }
    }

    /** @test */
    public function instructor_only_sees_the_booking_after_student_completes_waiver_and_payment_selection(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $instructor = $this->instructor(InstructorLevel::Level2);

        // Step 1: Student A submits details and lands on the Waiver page.
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'teaching',
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
                'emergency_contact' => 'Jane Doe - +63 917 000 0000',
                'signed_by' => 'Student A',
                'signature' => 'data:image/png;base64,AAAA',
                'agreement' => true,
                'participant_id' => $participant?->id,
            ])
            ->assertRedirect(route('bookings.payment', $booking->id));

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
        $intermediate1 = $this->anotherStudent('intermediate');
        $intermediate2 = $this->anotherStudent('intermediate');
        $intermediate3 = $this->anotherStudent('intermediate');
        $beginner2 = $this->anotherStudent('beginner');
        $beginner3 = $this->anotherStudent('beginner');
        $beginner4 = $this->anotherStudent('beginner');

        // Multi-student booking payloads (>1 in one account) are rejected across all levels.
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $levelOne->id), $this->payload(['student_count' => 2]))
            ->assertSessionHasErrors('student_count');

        $this->actingAs($intermediate1)
            ->post(route('bookings.store-details', $levelTwo->id), $this->payload(['student_count' => 2, 'session_type' => 'guiding']))
            ->assertSessionHasErrors('student_count');

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $levelThree->id), $this->payload(['student_count' => 3]))
            ->assertSessionHasErrors('student_count');

        // Level 2 allows up to 2 separate intermediate students in the same slot, rejecting the 3rd.
        $this->actingAs($intermediate1)
            ->post(route('bookings.store-details', $levelTwo->id), $this->payload(['student_count' => 1, 'session_type' => 'guiding']))
            ->assertRedirect();
        $this->actingAs($intermediate2)
            ->post(route('bookings.store-details', $levelTwo->id), $this->payload(['student_count' => 1, 'session_type' => 'guiding']))
            ->assertRedirect();
        $this->actingAs($intermediate3)
            ->post(route('bookings.store-details', $levelTwo->id), $this->payload(['student_count' => 1, 'session_type' => 'guiding']))
            ->assertSessionHas('error');

        // Level 3 allows up to 3 separate beginner students in the same slot, rejecting the 4th.
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $levelThree->id), $this->payload(['student_count' => 1]))
            ->assertRedirect();
        $this->actingAs($beginner2)
            ->post(route('bookings.store-details', $levelThree->id), $this->payload(['student_count' => 1]))
            ->assertRedirect();
        $this->actingAs($beginner3)
            ->post(route('bookings.store-details', $levelThree->id), $this->payload(['student_count' => 1]))
            ->assertRedirect();
        $this->actingAs($beginner4)
            ->post(route('bookings.store-details', $levelThree->id), $this->payload(['student_count' => 1]))
            ->assertSessionHas('error');
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
    public function end_times_endpoint_warns_that_a_time_slot_is_already_reserved_when_another_student_holds_it(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level1);
        $studentB = $this->anotherStudent('beginner');

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'start_time' => '06:00',
                'end_time' => '08:00',
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        $response = $this->actingAs($studentB)->getJson(route('api.availability.end-times', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'start_time' => '06:00',
            'students' => 1,
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
        ]))->assertOk();

        $this->assertSame([], $response->json('end_times'));
        $this->assertTrue($response->json('is_reserved'));
        $this->assertSame(0, $response->json('remaining_capacity'));
        $this->assertStringContainsString('already reserved', (string) $response->json('reason'));
    }

    /** @test */
    public function end_times_endpoint_explains_partial_reservation_and_skill_service_mismatch(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level2);
        $studentA = $this->anotherStudent('intermediate');
        $studentB = $this->anotherStudent('intermediate');
        $studentC = $this->anotherStudent('beginner');

        $this->actingAs($studentA)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'start_time' => '07:00',
                'end_time' => '09:00',
                'student_count' => 1,
                'session_type' => 'guiding',
            ]))
            ->assertRedirect();

        $partial = $this->actingAs($studentB)->getJson(route('api.availability.end-times', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'start_time' => '07:00',
            'students' => 2,
            'skill_level' => 'intermediate',
            'session_type' => 'guiding',
        ]))->assertOk();

        $this->assertSame([], $partial->json('end_times'));
        $this->assertTrue($partial->json('is_reserved'));
        $this->assertSame(1, $partial->json('remaining_capacity'));
        $this->assertStringContainsString('partially reserved', (string) $partial->json('reason'));

        $mismatch = $this->actingAs($studentC)->getJson(route('api.availability.end-times', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'start_time' => '07:00',
            'students' => 1,
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
        ]))->assertOk();

        $this->assertSame([], $mismatch->json('end_times'));
        $this->assertTrue($mismatch->json('is_reserved'));
        $this->assertSame(0, $mismatch->json('remaining_capacity'));
        $this->assertStringContainsString('already reserved', (string) $mismatch->json('reason'));
    }

    /** @test */
    public function availability_endpoint_lists_reserved_slots_separately_and_never_flags_unbooked_slots_as_reserved(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level1);
        $studentB = $this->anotherStudent('beginner');

        // Unbooked Level 1 instructor queried for 2 students must not mark empty slots as reserved.
        $unbookedResponse = $this->actingAs($studentB)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 2,
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
        ]))->assertOk();

        $this->assertSame([], $unbookedResponse->json('periods.morning.reserved_slots'));

        // Student A reserves 07:00-09:00 -> 07:00 and 08:00 move to reserved_slots; 09:00 stays open.
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'start_time' => '07:00',
                'end_time' => '09:00',
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        $partialDay = $this->actingAs($studentB)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 1,
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
        ]))->assertOk();

        $openSlots = $partialDay->json('periods.morning.slots');
        $reservedSlots = $partialDay->json('periods.morning.reserved_slots');

        $this->assertNotContains('07:00', $openSlots);
        $this->assertNotContains('08:00', $openSlots);
        $this->assertContains('09:00', $openSlots);
        $this->assertContains('07:00', $reservedSlots);
        $this->assertContains('08:00', $reservedSlots);
        $this->assertNotContains('09:00', $reservedSlots);

        // When all morning slots (06:00-12:00) are reserved on another Level 1 instructor.
        $fullInstructor = $this->instructor(InstructorLevel::Level1);
        $this->bookingFor($fullInstructor, [
            'start_time' => '06:00',
            'end_time' => '12:00',
            'duration_hours' => 6,
            'status' => BookingStatus::Confirmed,
        ]);

        $fullMorning = $this->actingAs($studentB)->getJson(route('api.availability', [
            'instructor' => $fullInstructor->id,
            'date' => $this->day->format('Y-m-d'),
            'students' => 1,
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
        ]))->assertOk();

        $this->assertFalse($fullMorning->json('periods.morning.available'));
        $this->assertNotEmpty($fullMorning->json('periods.morning.reserved_slots'));
        $this->assertStringContainsString('already reserved', (string) $fullMorning->json('periods.morning.reason'));
    }

    /** @test */
    public function instructor_schedule_calendar_and_bookings_show_reserved_holds_without_allowing_accept_or_inflating_pending_badge(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level1);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'start_time' => '07:00',
                'end_time' => '09:00',
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        $booking = Booking::where('instructor_id', $instructor->id)->firstOrFail();
        $this->assertSame(BookingStatus::Reserved, $booking->status);

        $date = $this->day->format('Y-m-d');

        $this->actingAs($instructor)
            ->get(route('instructor.availability.index', ['date' => $date]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('initialSchedule.summary.reserved', 1)
                ->where('initialSchedule.summary.pending', 0)
                ->where('nav.pendingRequests', 0));

        $schedule = $this->actingAs($instructor)
            ->getJson(route('instructor.availability.schedule', ['week' => $date]))
            ->assertOk();

        $dayEntry = collect($schedule->json('days'))->firstWhere('date', $date);
        $this->assertNotNull($dayEntry);
        $this->assertCount(1, $dayEntry['bookings']);

        $slotBooking = $dayEntry['bookings'][0];
        $this->assertSame('reserved', $slotBooking['status']);
        $this->assertTrue($slotBooking['is_reserved']);
        $this->assertFalse($slotBooking['can_accept']);
        $this->assertFalse($slotBooking['waiver_signed']);
        $this->assertSame('07:00', $slotBooking['start_time']);
        $this->assertSame('09:00', $slotBooking['end_time']);
        $this->assertGreaterThan(0, $slotBooking['hold_remaining_seconds']);

        $this->actingAs($instructor)
            ->get(route('instructor.bookings.index', ['status' => 'reserved']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('counts.reserved', 1)
                ->where('counts.pending', 0)
                ->where('nav.pendingRequests', 0)
                ->where('bookings.data.0.status', 'reserved')
                ->where('bookings.data.0.is_reserved', true)
                ->where('bookings.data.0.can_be_accepted', false)
                ->where('bookings.data.0.can_be_declined', false)
                ->where('bookings.data.0.accept_block', 'awaiting_waiver'));

        $this->actingAs($instructor)
            ->get(route('instructor.bookings.show', $booking->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isReserved', true)
                ->where('canBeAccepted', false)
                ->where('acceptBlock', 'awaiting_waiver'));

        $this->actingAs($instructor)
            ->patch(route('instructor.bookings.confirm', $booking->id))
            ->assertSessionHas('error');
        $this->assertSame(BookingStatus::Reserved, $booking->fresh()->status);

        $this->actingAs($instructor)
            ->post(route('instructor.bookings.decline', $booking->id))
            ->assertSessionHas('error');
        $this->assertSame(BookingStatus::Reserved, $booking->fresh()->status);
    }

    /** @test */
    public function student_bookings_exposes_reserved_hold_with_resume_url_and_exact_local_date(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level1);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'start_time' => '07:00',
                'end_time' => '09:00',
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        $booking = Booking::where('instructor_id', $instructor->id)->firstOrFail();
        $date = $this->day->format('Y-m-d');
        $waiverUrl = route('bookings.waiver', $booking->id);

        $this->actingAs($this->student)
            ->get(route('student.bookings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('counts.upcoming', 1)
                ->where('nav.upcomingBookings', 1)
                ->where('bookings.data.0.status', 'reserved')
                ->where('bookings.data.0.is_reserved', true)
                ->where('bookings.data.0.date', $date)
                ->where('bookings.data.0.resume_url', $waiverUrl)
                ->where('bookings.data.0.hold_remaining_seconds', fn ($seconds) => $seconds > 0));

        $this->actingAs($this->student)
            ->get(route('student.bookings.show', $booking->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('booking.date', $date)
                ->where('booking.is_reserved', true)
                ->where('booking.resume_url', $waiverUrl));
    }

    /** @test */
    public function abandoning_waiver_with_safe_relative_redirect_releases_slot_and_redirects_there(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level2);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        $booking = Booking::where('instructor_id', $instructor->id)->firstOrFail();

        $this->actingAs($this->student)
            ->post(route('bookings.abandon', $booking->id), [
                'redirect_to' => '/student/dashboard',
            ])
            ->assertRedirect('/student/dashboard')
            ->assertSessionHas('info');

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);

        $slotsAfterAbandon = $this->actingAs($this->student)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $this->day->format('Y-m-d'),
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
        ]))->assertOk()->json('periods.morning.slots');

        $this->assertContains('07:00', $slotsAfterAbandon);
    }

    /** @test */
    public function abandoning_waiver_ignores_external_redirect_urls(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level2);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        $booking = Booking::where('instructor_id', $instructor->id)->firstOrFail();

        $this->actingAs($this->student)
            ->post(route('bookings.abandon', $booking->id), [
                'redirect_to' => 'https://evil.example.com/phish',
            ])
            ->assertRedirect(route('bookings.create', $instructor->id));

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);

        // Also reject protocol-relative URLs on a second booking
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        $secondBooking = Booking::where('instructor_id', $instructor->id)
            ->where('id', '!=', $booking->id)
            ->firstOrFail();

        $this->actingAs($this->student)
            ->post(route('bookings.abandon', $secondBooking->id), [
                'redirect_to' => '//evil.example.com/phish',
            ])
            ->assertRedirect(route('bookings.create', $instructor->id));

        $this->assertSame(BookingStatus::Cancelled, $secondBooking->fresh()->status);
    }

    /** @test */
    public function visiting_waiver_after_abandonment_redirects_back_to_booking_create(): void
    {
        $instructor = $this->instructor(InstructorLevel::Level2);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'student_count' => 1,
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        $booking = Booking::where('instructor_id', $instructor->id)->firstOrFail();

        $this->actingAs($this->student)
            ->post(route('bookings.abandon', $booking->id))
            ->assertRedirect(route('bookings.create', $instructor->id));

        $this->actingAs($this->student)
            ->get(route('bookings.waiver', $booking->id))
            ->assertRedirect(route('bookings.create', $instructor->id))
            ->assertSessionHas('info');

        $this->actingAs($this->student)
            ->get(route('bookings.parental-consent', $booking->id))
            ->assertRedirect(route('bookings.create', $instructor->id))
            ->assertSessionHas('info');
    }

    /** @test */
    public function non_contiguous_open_slots_cannot_be_booked_across_closed_break_hours(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor']);
        $instructor->assignRole('instructor');
        $instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level3,
            'status' => 'active',
            'rate_per_hour' => 1000,
            'guiding_rate' => 1200,
            'coaching_rate' => 1800,
            'auto_accept_bookings' => false,
        ]);

        // 08:00 is closed as a break; only 07:00, 09:00, and 10:00 are open.
        Availability::create([
            'user_id' => $instructor->id,
            'day_of_week' => (int) $this->day->format('w'),
            'time_period' => 'morning',
            'is_available' => true,
            'start_time' => '06:00',
            'end_time' => '12:00',
            'open_slots' => ['07:00', '09:00', '10:00'],
            'slot_duration_minutes' => 60,
            'specific_date' => null,
        ]);

        $intermediate = $this->anotherStudent('intermediate');
        $date = $this->day->format('Y-m-d');

        // Guiding (2h minimum) cannot start at 07:00 because 08:00 is closed; 09:00 IS contiguous (09:00-11:00).
        $guidingAvail = $this->actingAs($intermediate)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $date,
            'skill_level' => 'intermediate',
            'session_type' => 'guiding',
        ]))->assertOk();

        $this->assertNotContains('07:00', $guidingAvail->json('periods.morning.slots'));
        $this->assertContains('09:00', $guidingAvail->json('periods.morning.slots'));

        // Teaching (1h minimum) starting at 07:00 can only end at 08:00 — never spanning closed 08:00 to 09:00.
        $endTimes = $this->actingAs($this->student)->getJson(route('api.availability.end-times', [
            'instructor' => $instructor->id,
            'date' => $date,
            'start_time' => '07:00',
            'students' => 1,
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
        ]))->assertOk();

        $this->assertSame(['08:00'], $endTimes->json('end_times'));

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $instructor->id), $this->payload([
                'start_time' => '07:00',
                'end_time' => '09:00',
                'session_type' => 'teaching',
            ]))
            ->assertSessionHas('error');

        $this->assertSame(0, Booking::where('instructor_id', $instructor->id)->count());
    }
}
