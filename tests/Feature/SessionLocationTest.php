<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\InstructorLevel;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use App\Services\LocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Real-time session location.
 *
 * Covers the three ways this feature can go wrong: pinging outside a live
 * session, accepting coordinates that are not on Siargao, and leaking a
 * student's live position to somebody else.
 */
class SessionLocationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $instructor;

    private User $otherStudent;

    private SurfSpot $surfSpot;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');

        $this->student = User::factory()->create(['role' => 'student']);
        $this->student->assignRole('student');

        $this->otherStudent = User::factory()->create(['role' => 'student']);
        $this->otherStudent->assignRole('student');

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 1000,
        ]);

        // Cloud 9 - the reference point for distance assertions.
        $this->surfSpot = SurfSpot::factory()->create([
            'name' => 'Cloud 9',
            'area' => 'General Luna',
            'difficulty' => 'advanced',
            'latitude' => 9.8119,
            'longitude' => 126.1672,
        ]);
    }

    private function liveBooking(array $attributes = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->surfSpot->id,
            'status' => BookingStatus::InProgress,
            'date' => today()->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'duration_hours' => 1,
            'started_at' => now()->subMinutes(10),
        ], $attributes));
    }

    public function test_instructor_can_ping_location_during_a_live_session(): void
    {
        $booking = $this->liveBooking();

        $this->actingAs($this->instructor)
            ->postJson(route('instructor.bookings.location.update', $booking), [
                'latitude' => 9.8100,
                'longitude' => 126.1660,
                'accuracy_m' => 12.5,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Location recorded.');

        $this->assertDatabaseHas('session_locations', [
            'booking_id' => $booking->id,
            'instructor_id' => $this->instructor->id,
        ]);
    }

    public function test_ping_is_rejected_when_the_session_is_not_in_progress(): void
    {
        $booking = $this->liveBooking(['status' => BookingStatus::Confirmed]);

        $this->actingAs($this->instructor)
            ->postJson(route('instructor.bookings.location.update', $booking), [
                'latitude' => 9.8100,
                'longitude' => 126.1660,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('session_locations', 0);
    }

    public function test_ping_outside_siargao_is_rejected(): void
    {
        $booking = $this->liveBooking();

        // Manila.
        $this->actingAs($this->instructor)
            ->postJson(route('instructor.bookings.location.update', $booking), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'That position is outside Siargao Island. Live tracking only accepts pings from the island.'
            );

        $this->assertDatabaseCount('session_locations', 0);
    }

    public function test_another_instructor_cannot_ping_someone_elses_session(): void
    {
        $booking = $this->liveBooking();

        $intruder = User::factory()->create(['role' => 'instructor']);
        $intruder->assignRole('instructor');

        $this->actingAs($intruder)
            ->postJson(route('instructor.bookings.location.update', $booking), [
                'latitude' => 9.8100,
                'longitude' => 126.1660,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('session_locations', 0);
    }

    public function test_student_can_read_the_live_state_and_distance_to_the_break(): void
    {
        $booking = $this->liveBooking();

        $this->actingAs($this->instructor)->postJson(
            route('instructor.bookings.location.update', $booking),
            ['latitude' => 9.8100, 'longitude' => 126.1660]
        )->assertOk();

        $response = $this->actingAs($this->student)
            ->getJson(route('student.bookings.location', $booking));

        $response->assertOk();
        $response->assertJsonPath('live.booking_id', $booking->id);
        $response->assertJsonPath('live.on_site', true);

        // Roughly 220 m from Cloud 9 - well inside the 400 m on-site radius.
        $distance = $response->json('live.distance_from_spot_m');
        $this->assertNotNull($distance);
        $this->assertLessThan(LocationService::ON_SITE_RADIUS_METERS, $distance);
    }

    public function test_another_student_cannot_read_the_live_state(): void
    {
        $booking = $this->liveBooking();

        $this->actingAs($this->otherStudent)
            ->getJson(route('student.bookings.location', $booking))
            ->assertForbidden();
    }

    public function test_distance_beyond_the_radius_is_reported_as_off_site(): void
    {
        $booking = $this->liveBooking();

        // ~8 km east of Cloud 9, still inside the Siargao bounding box.
        $this->actingAs($this->instructor)->postJson(
            route('instructor.bookings.location.update', $booking),
            ['latitude' => 9.8119, 'longitude' => 126.2450]
        )->assertOk();

        $response = $this->actingAs($this->student)
            ->getJson(route('student.bookings.location', $booking));

        $response->assertOk();
        $response->assertJsonPath('live.on_site', false);
    }

    public function test_haversine_distance_between_cloud_9_and_general_luna_town(): void
    {
        $distance = LocationService::haversineMeters(9.8119, 126.1672, 9.7792, 126.1583);

        // ~3.7 km; assert a sane band rather than an exact figure.
        $this->assertGreaterThan(3000, $distance);
        $this->assertLessThan(4500, $distance);
    }

    public function test_repeated_pings_are_all_persisted_for_the_audit_trail(): void
    {
        $booking = $this->liveBooking();

        $service = app(LocationService::class);

        $service->record($booking, ['latitude' => 9.8119, 'longitude' => 126.1672]);
        $service->record($booking, ['latitude' => 9.8119, 'longitude' => 126.1673]);

        // The throttle only suppresses *broadcasts*; the trail keeps every ping.
        $this->assertDatabaseCount('session_locations', 2);
        $this->assertSame(2, $service->trailForBooking($booking)->count());
    }
}
