<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\InstructorLevel;
use App\Enums\InstructorStatus;
use App\Events\InstructorStatusChanged;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use App\Models\Waiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression tests for the operational gaps found in the audit:
 *  - suspensions that never lifted themselves,
 *  - `InstructorStatusChanged` referencing a non-existent enum case,
 *  - expired waivers that were never cleaned up.
 */
class MaintenanceCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');
    }

    private function instructorWithProfile(array $profile = []): User
    {
        $instructor = User::factory()->create(['role' => 'instructor']);
        $instructor->assignRole('instructor');
        $instructor->instructorProfile()->create(array_merge([
            'level' => InstructorLevel::Level2,
            'status' => 'suspended',
            'rate_per_hour' => 1000,
            'strike_count' => 3,
            'suspended_until' => now()->subDay(),
        ], $profile));

        return $instructor;
    }

    public function test_expired_suspension_is_lifted_and_strikes_are_cleared(): void
    {
        $instructor = $this->instructorWithProfile();

        $this->artisan('instructors:lift-expired-suspensions')->assertSuccessful();

        $profile = $instructor->instructorProfile->fresh();

        $this->assertSame('active', $profile->status->value);
        $this->assertNull($profile->suspended_until);
        $this->assertSame(0, $profile->strike_count);
    }

    public function test_active_suspension_is_left_alone(): void
    {
        $instructor = $this->instructorWithProfile([
            'suspended_until' => now()->addWeek(),
        ]);

        $this->artisan('instructors:lift-expired-suspensions')->assertSuccessful();

        $profile = $instructor->instructorProfile->fresh();

        $this->assertSame('suspended', $profile->status->value);
        $this->assertNotNull($profile->suspended_until);
        $this->assertSame(3, $profile->strike_count);
    }

    public function test_instructor_status_change_event_builds_payload_for_every_status(): void
    {
        // Guards the removed InstructorStatus::Rejected reference: constructing
        // and serialising the event must work for every real status.
        $instructor = User::factory()->create(['role' => 'instructor']);
        $instructor->assignRole('instructor');

        foreach (InstructorStatus::cases() as $status) {
            $event = new InstructorStatusChanged(
                $instructor,
                InstructorStatus::PendingVerification,
                $status
            );

            $payload = $event->broadcastWith();

            $this->assertSame($status->value, $payload['new_status']);
            $this->assertNotEmpty($payload['message']);
        }
    }

    public function test_waiver_cleanup_removes_documents_past_retention(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');
        $instructor = $this->instructorWithProfile(['status' => 'active', 'strike_count' => 0, 'suspended_until' => null]);
        $spot = SurfSpot::factory()->create();

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'surf_spot_id' => $spot->id,
            'status' => BookingStatus::Completed,
        ]);

        Waiver::create([
            'booking_id' => $booking->id,
            'type' => 'liability',
            'signed_by' => $student->name,
            'signature_data' => 'data:image/png;base64,AAAA',
            'pdf_path' => 'waivers/expired.pdf',
            'signed_at' => now()->subYears(8),
            'retention_until' => now()->subDay(),
        ]);

        Waiver::create([
            'booking_id' => $booking->id,
            'type' => 'parental_consent',
            'signed_by' => $student->name,
            'signature_data' => 'data:image/png;base64,AAAA',
            'pdf_path' => 'waivers/current.pdf',
            'signed_at' => now(),
            'retention_until' => now()->addYears(7),
        ]);

        $this->artisan('waivers:cleanup-expired')->assertSuccessful();

        $this->assertDatabaseMissing('waivers', ['pdf_path' => 'waivers/expired.pdf']);
        $this->assertDatabaseHas('waivers', ['pdf_path' => 'waivers/current.pdf']);
    }
}
