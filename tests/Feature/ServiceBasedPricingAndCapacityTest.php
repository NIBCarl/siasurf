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
use Illuminate\Testing\Fluent\AssertableJson;
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
        $this->assertSame('1500.00', $booking->lesson_price);
        $this->assertSame('105.00', $booking->service_fee_amount);
        $this->assertSame('1605.00', $booking->total_amount); // (750 * 2 hrs) + 7% service fee
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
        $this->assertSame('650.00', $booking->lesson_price);
        $this->assertSame('45.50', $booking->service_fee_amount);
        $this->assertSame('695.50', $booking->total_amount); // 1 hour Surf Experience + 7% service fee
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
        $this->assertSame('2500.00', $booking->lesson_price);
        $this->assertSame('175.00', $booking->service_fee_amount);
        $this->assertSame('2675.00', $booking->total_amount);
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
        $this->assertSame('3700.00', $booking->lesson_price);
        $this->assertSame('259.00', $booking->service_fee_amount);
        $this->assertSame('3959.00', $booking->total_amount);
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
            ->assertSessionHasErrors(['student_count', 'participants']);

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

    public function test_service_type_is_strictly_fixed_to_student_skill_level_and_enforces_two_hour_minimum_for_guiding_and_coaching(): void
    {
        $instructor = $this->createInstructor(InstructorLevel::Level3);
        Availability::create([
            'user_id' => $instructor->id,
            'day_of_week' => (int) $this->day->format('w'),
            'time_period' => 'afternoon',
            'is_available' => true,
            'start_time' => '12:00',
            'end_time' => '18:00',
            'slot_duration_minutes' => 60,
            'specific_date' => null,
        ]);

        $beginner = $this->createStudent('beginner');
        $intermediate = $this->createStudent('intermediate');
        $advanced = $this->createStudent('advanced');
        $dateStr = $this->day->format('Y-m-d');

        // Beginner is locked to Teaching (1h intro allowed; Guiding/Coaching rejected).
        $this->actingAs($beginner)
            ->get(route('bookings.create', $instructor->id))
            ->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $page
                ->where('serviceOptions.0.type', 'teaching')
                ->where('defaultSessionType', 'teaching')
                ->where('minimumDurationMinutes', 60)
                ->has('serviceOptions', 1));

        $this->actingAs($beginner)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->beginnerSpot, [
                'session_type' => 'coaching',
            ]))
            ->assertSessionHas('error');

        $this->actingAs($beginner)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->beginnerSpot, [
                'session_type' => 'teaching',
                'start_time' => '06:00',
                'end_time' => '07:00',
            ]))
            ->assertRedirect();

        // Intermediate is locked to Guiding (minimum 2h: 11:00 & 17:00 excluded; 1h POST rejected; 2h & 3h extended accepted).
        $this->actingAs($intermediate)
            ->get(route('bookings.create', $instructor->id))
            ->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $page
                ->where('serviceOptions.0.type', 'guiding')
                ->where('defaultSessionType', 'guiding')
                ->where('minimumDurationMinutes', 120)
                ->has('serviceOptions', 1));

        $intAvail = $this->actingAs($intermediate)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $dateStr,
            'skill_level' => 'intermediate',
            'session_type' => 'guiding',
        ]))->assertOk();

        $this->assertNotContains('11:00', $intAvail->json('periods.morning.slots'));
        $this->assertNotContains('17:00', $intAvail->json('periods.afternoon.slots'));
        $this->assertContains('10:00', $intAvail->json('periods.morning.slots'));
        $this->assertContains('16:00', $intAvail->json('periods.afternoon.slots'));
        $this->assertContains('06:00', $intAvail->json('periods.morning.reserved_slots'));
        $this->assertSame('skill_mismatch', $intAvail->json('periods.morning.reserved_slot_details.06:00.reason_code'));

        $this->actingAs($intermediate)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->intermediateSpot, [
                'session_type' => 'teaching',
                'start_time' => '08:00',
                'end_time' => '10:00',
            ]))
            ->assertSessionHas('error');

        $this->actingAs($intermediate)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->intermediateSpot, [
                'session_type' => 'guiding',
                'start_time' => '07:00',
                'end_time' => '08:00',
            ]))
            ->assertSessionHas('error');

        $this->actingAs($intermediate)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->intermediateSpot, [
                'session_type' => 'guiding',
                'start_time' => '13:00',
                'end_time' => '16:00',
            ]))
            ->assertRedirect();

        // Advanced is locked to Coaching (minimum 2h: 11:00 & 17:00 excluded; 1h POST rejected; 2h+ accepted).
        $this->actingAs($advanced)
            ->get(route('bookings.create', $instructor->id))
            ->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $page
                ->where('serviceOptions.0.type', 'coaching')
                ->where('defaultSessionType', 'coaching')
                ->where('minimumDurationMinutes', 120)
                ->has('serviceOptions', 1));

        $advAvail = $this->actingAs($advanced)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $dateStr,
            'skill_level' => 'advanced',
            'session_type' => 'coaching',
        ]))->assertOk();

        $this->assertNotContains('11:00', $advAvail->json('periods.morning.slots'));
        $this->assertNotContains('17:00', $advAvail->json('periods.afternoon.slots'));

        $this->actingAs($advanced)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->advancedSpot, [
                'session_type' => 'coaching',
                'start_time' => '07:00',
                'end_time' => '08:00',
            ]))
            ->assertSessionHas('error');

        $this->actingAs($advanced)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->advancedSpot, [
                'session_type' => 'coaching',
                'start_time' => '07:00',
                'end_time' => '09:00',
            ]))
            ->assertRedirect();
    }

    public function test_rolling_per_hour_capacity_reconciles_extended_and_standard_coaching_bookings_with_spot_lock(): void
    {
        $spotA = $this->advancedSpot;
        $spotB = SurfSpot::factory()->create(['difficulty' => 'advanced', 'name' => 'Pacifico Reef']);

        $instructor = $this->createInstructor(InstructorLevel::Level3);
        $adv1 = $this->createStudent('advanced');
        $adv2 = $this->createStudent('advanced');
        $adv3 = $this->createStudent('advanced');
        $adv4 = $this->createStudent('advanced');
        $dateStr = $this->day->format('Y-m-d');

        // Student A books an extended 3-hour Coaching session (06:00-09:00) at Spot A (1/3 seats).
        $this->actingAs($adv1)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($spotA, [
                'start_time' => '06:00',
                'end_time' => '09:00',
                'session_type' => 'coaching',
            ]))
            ->assertRedirect();

        // Student B checking 06:00 end-times gets 08:00 minimum (no 07:00) and surf spot locked to Spot A.
        $endTimesResp = $this->actingAs($adv2)->getJson(route('api.availability.end-times', [
            'instructor' => $instructor->id,
            'date' => $dateStr,
            'start_time' => '06:00',
            'students' => 1,
            'skill_level' => 'advanced',
            'session_type' => 'coaching',
        ]))->assertOk();

        $this->assertSame(['08:00', '09:00', '10:00', '11:00', '12:00'], $endTimesResp->json('end_times'));
        $this->assertSame($spotA->id, $endTimesResp->json('end_time_spots.08:00.id'));
        $this->assertSame($spotA->id, $endTimesResp->json('locked_surf_spot.id'));

        // Student B cannot join 06:00-08:00 at a DIFFERENT surf spot (Spot B).
        $this->actingAs($adv2)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($spotB, [
                'start_time' => '06:00',
                'end_time' => '08:00',
                'session_type' => 'coaching',
            ]))
            ->assertSessionHas('error');

        // Student B CAN join 06:00-08:00 (standard 2h) at locked Spot A while Student A continues until 09:00.
        $this->actingAs($adv2)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($spotA, [
                'start_time' => '06:00',
                'end_time' => '08:00',
                'session_type' => 'coaching',
            ]))
            ->assertRedirect();

        // Rolling capacity check: 06:00-08:00 has 1 seat left (2/3 used), while 08:00-10:00 has 2 seats left!
        $availC = $this->actingAs($adv3)->getJson(route('api.availability', [
            'instructor' => $instructor->id,
            'date' => $dateStr,
            'students' => 1,
            'skill_level' => 'advanced',
            'session_type' => 'coaching',
        ]))->assertOk();

        $this->assertSame(1, $availC->json('periods.morning.slot_remaining.06:00'));
        $this->assertSame(2, $availC->json('periods.morning.slot_remaining.08:00'));

        // Student C takes the 3rd seat in 06:00-08:00 at Spot A.
        $this->actingAs($adv3)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($spotA, [
                'start_time' => '06:00',
                'end_time' => '08:00',
                'session_type' => 'coaching',
            ]))
            ->assertRedirect();

        // Student D is blocked from 06:00-08:00 (now 3/3 full), but CAN book 08:00-10:00 overlapping Student A's 3rd hour!
        $this->actingAs($adv4)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($spotA, [
                'start_time' => '06:00',
                'end_time' => '08:00',
                'session_type' => 'coaching',
            ]))
            ->assertSessionHas('error');

        $this->actingAs($adv4)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($spotA, [
                'start_time' => '08:00',
                'end_time' => '10:00',
                'session_type' => 'coaching',
            ]))
            ->assertRedirect();

        // Bridging two non-overlapping bookings at different spots (06:00-08:00 Spot A + 08:00-10:00 Spot B) is rejected.
        $bridgeInstructor = $this->createInstructor(InstructorLevel::Level3);
        $s1 = $this->createStudent('advanced');
        $s2 = $this->createStudent('advanced');
        $s3 = $this->createStudent('advanced');

        $this->actingAs($s1)
            ->post(route('bookings.store-details', $bridgeInstructor->id), $this->bookingPayload($spotA, [
                'start_time' => '06:00',
                'end_time' => '08:00',
                'session_type' => 'coaching',
            ]))
            ->assertRedirect();

        $this->actingAs($s2)
            ->post(route('bookings.store-details', $bridgeInstructor->id), $this->bookingPayload($spotB, [
                'start_time' => '08:00',
                'end_time' => '10:00',
                'session_type' => 'coaching',
            ]))
            ->assertRedirect();

        $this->actingAs($s3)
            ->post(route('bookings.store-details', $bridgeInstructor->id), $this->bookingPayload($spotA, [
                'start_time' => '07:00',
                'end_time' => '09:00',
                'session_type' => 'coaching',
            ]))
            ->assertSessionHas('error');
    }
}
