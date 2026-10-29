<?php

namespace Tests\Feature;

use App\Enums\InstructorLevel;
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

/**
 * FR-2.x / safety rule 5: a surfer may only book a break at or below their own
 * level. Intermediate surfers must never see or submit an advanced spot.
 */
class SurfSpotSkillTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;
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

        // Level 3 can teach every level, so the only variable in these tests is
        // the student's own skill versus the break's difficulty.
        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level3,
            'status' => 'active',
            'rate_per_hour' => 1000,
        ]);

        $this->day = now()->addDays(3)->startOfDay();

        Availability::create([
            'user_id' => $this->instructor->id,
            'day_of_week' => (int) $this->day->format('w'),
            'time_period' => 'morning',
            'is_available' => true,
            'start_time' => '06:00',
            'end_time' => '12:00',
            'slot_duration_minutes' => 60,
            'specific_date' => null,
        ]);

        $this->beginnerSpot = SurfSpot::factory()->create(['difficulty' => 'beginner', 'name' => 'Jacking Horse']);
        $this->intermediateSpot = SurfSpot::factory()->create(['difficulty' => 'intermediate', 'name' => 'Cloud 9']);
        $this->advancedSpot = SurfSpot::factory()->create(['difficulty' => 'advanced', 'name' => 'Rock Islands']);
    }

    private function studentWithLevel(string $level): User
    {
        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');
        $student->studentProfile()->create([
            'skill_level' => $level,
            'is_first_time' => false,
            'status' => 'active',
        ]);

        return $student;
    }

    private function payload(int $spotId, array $overrides = []): array
    {
        return array_merge([
            'date' => $this->day->format('Y-m-d'),
            'start_time' => '07:00',
            'end_time' => '08:00',
            'student_age' => 25,
            'height' => 175,
            'weight' => 70,
            'student_count' => 1,
            'has_board' => false,
            'surf_spot_id' => $spotId,
        ], $overrides);
    }

    /** @test */
    public function an_intermediate_student_cannot_book_an_advanced_break(): void
    {
        $student = $this->studentWithLevel('intermediate');

        $this->actingAs($student)
            ->post(route('bookings.store-details', $this->instructor->id), $this->payload($this->advancedSpot->id))
            ->assertSessionHas('error');

        $this->assertSame(0, Booking::count());
    }

    /** @test */
    public function an_intermediate_student_can_book_an_intermediate_break(): void
    {
        $student = $this->studentWithLevel('intermediate');

        $this->actingAs($student)
            ->post(route('bookings.store-details', $this->instructor->id), $this->payload($this->intermediateSpot->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1, Booking::count());
    }

    /** @test */
    public function a_beginner_student_cannot_book_an_intermediate_break(): void
    {
        $student = $this->studentWithLevel('beginner');

        $this->actingAs($student)
            ->post(route('bookings.store-details', $this->instructor->id), $this->payload($this->intermediateSpot->id))
            ->assertSessionHas('error');

        $this->assertSame(0, Booking::count());
    }

    /** @test */
    public function an_advanced_student_can_book_an_advanced_break(): void
    {
        $student = $this->studentWithLevel('advanced');

        $this->actingAs($student)
            ->post(route('bookings.store-details', $this->instructor->id), $this->payload($this->advancedSpot->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1, Booking::count());
    }

    /** @test */
    public function the_booking_form_only_lists_breaks_the_student_may_use(): void
    {
        $student = $this->studentWithLevel('intermediate');

        $response = $this->actingAs($student)
            ->get(route('bookings.create', $this->instructor->id));

        $response->assertOk()->assertInertia(function (AssertableJson $page) {
            $props = $page->toArray()['props'];
            $difficulties = collect($props['surfSpots'])
                ->pluck('difficulty')
                ->unique()
                ->values()
                ->all();

            $this->assertEqualsCanonicalizing(['beginner', 'intermediate'], $difficulties);
            $this->assertNotEmpty($props['surfSpotRule']);
        });
    }

    /** @test */
    public function a_beginner_companion_restricts_the_whole_group_to_beginner_breaks(): void
    {
        $student = $this->studentWithLevel('intermediate');

        $payload = $this->payload($this->intermediateSpot->id, [
            'participants' => [
                [
                    'full_name' => 'Primary Surfer',
                    'age' => 28,
                    'height' => 175,
                    'weight' => 70,
                    'skill_level' => 'intermediate',
                    'has_board' => true,
                ],
                [
                    'full_name' => 'First Timer',
                    'age' => 22,
                    'height' => 165,
                    'weight' => 60,
                    'skill_level' => 'beginner',
                    'has_board' => true,
                ],
            ],
        ]);

        $this->actingAs($student)
            ->post(route('bookings.store-details', $this->instructor->id), $payload)
            ->assertSessionHas('error');

        $this->assertSame(0, Booking::count());
    }

    /** @test */
    public function an_advanced_companion_does_not_raise_an_intermediate_primary(): void
    {
        $student = $this->studentWithLevel('intermediate');

        $payload = $this->payload($this->advancedSpot->id, [
            'participants' => [
                [
                    'full_name' => 'Primary Surfer',
                    'age' => 28,
                    'height' => 175,
                    'weight' => 70,
                    'skill_level' => 'intermediate',
                    'has_board' => true,
                ],
                [
                    'full_name' => 'Advanced Friend',
                    'age' => 30,
                    'height' => 180,
                    'weight' => 75,
                    'skill_level' => 'advanced',
                    'has_board' => true,
                ],
            ],
        ]);

        $this->actingAs($student)
            ->post(route('bookings.store-details', $this->instructor->id), $payload)
            ->assertSessionHas('error');

        $this->assertSame(0, Booking::count());
    }
}
