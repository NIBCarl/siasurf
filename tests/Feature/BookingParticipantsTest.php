<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\InstructorLevel;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Group bookings: the profile is the source of truth for the account holder's
 * skill level, companions are declared per person, and every participant signs
 * their own waiver in a guided flow.
 */
class BookingParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $instructor;
    private SurfSpot $surfSpot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        $this->student = User::factory()->create(['role' => 'student']);
        $this->student->assignRole('student');
        $this->student->studentProfile()->create([
            'skill_level' => 'intermediate',
            'is_first_time' => false,
            'status' => 'active',
        ]);

        // Level 3 can take a group of up to 5.
        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level3,
            'status' => 'active',
            'rate_per_hour' => 1500,
            'auto_accept_bookings' => false,
        ]);

        $this->surfSpot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'date' => now()->addDays(3)->format('Y-m-d'),
            'start_time' => '09:00',
            'skill_level' => 'beginner', // deliberately wrong; profile must win
            'student_age' => 30,
            'height' => 175,
            'weight' => 70,
            'student_count' => 1,
            'has_board' => false,
            'surf_spot_id' => $this->surfSpot->id,
        ], $overrides);
    }

    /** @test */
    public function the_profile_skill_level_overrides_what_the_client_sends()
    {
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor->id), $this->basePayload())
            ->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();

        // Profile is intermediate; the payload lied and said beginner.
        $this->assertSame('intermediate', $booking->skill_level->value);
        $this->assertSame('intermediate', $booking->primaryParticipant->skill_level->value);
    }

    /** @test */
    public function companions_are_stored_as_participants_and_counted()
    {
        $payload = $this->basePayload([
            'participants' => [
                [
                    'full_name' => 'Primary Surfer',
                    'age' => 30,
                    'height' => 175,
                    'weight' => 70,
                    'skill_level' => 'intermediate',
                    'has_board' => false,
                ],
                [
                    'full_name' => 'Companion One',
                    'age' => 28,
                    'height' => 160,
                    'weight' => 55,
                    'skill_level' => 'beginner',
                    'has_board' => true,
                    'emergency_contact_name' => 'Jane',
                    'emergency_contact_phone' => '+63 900 000 0000',
                ],
            ],
        ]);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor->id), $payload)
            ->assertRedirect();

        $booking = Booking::with('participants')->latest('id')->firstOrFail();

        $this->assertSame(2, $booking->student_count);
        $this->assertCount(2, $booking->participants);
        $this->assertSame('primary', $booking->participants[0]->role);
        $this->assertSame('companion', $booking->participants[1]->role);
        $this->assertSame('Companion One', $booking->participants[1]->full_name);
        $this->assertSame('beginner', $booking->participants[1]->skill_level->value);
    }

    /** @test */
    public function a_minor_companion_forces_a_level_three_instructor()
    {
        $levelOne = User::factory()->create(['role' => 'instructor']);
        $levelOne->assignRole('instructor');
        $levelOne->instructorProfile()->create([
            'level' => InstructorLevel::Level1,
            'status' => 'active',
            'rate_per_hour' => 600,
        ]);

        $payload = $this->basePayload([
            'participants' => [
                ['full_name' => 'Adult', 'age' => 30, 'height' => 175, 'weight' => 70, 'skill_level' => 'beginner', 'has_board' => false],
                ['full_name' => 'Kid', 'age' => 10, 'height' => 140, 'weight' => 35, 'skill_level' => 'beginner', 'has_board' => false],
            ],
        ]);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $levelOne->id), $payload)
            ->assertSessionHas('error');

        $this->assertDatabaseCount('bookings', 0);
    }

    /** @test */
    public function each_participant_signs_their_own_waiver_in_a_guided_flow()
    {
        $payload = $this->basePayload([
            'participants' => [
                ['full_name' => 'Primary Surfer', 'age' => 30, 'height' => 175, 'weight' => 70, 'skill_level' => 'intermediate', 'has_board' => false],
                ['full_name' => 'Companion One', 'age' => 28, 'height' => 160, 'weight' => 55, 'skill_level' => 'beginner', 'has_board' => true],
            ],
        ]);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor->id), $payload)
            ->assertRedirect();

        $booking = Booking::with('participants')->latest('id')->firstOrFail();
        $primary = $booking->participants[0];
        $companion = $booking->participants[1];

        // First visit: signing for the primary participant.
        $this->actingAs($this->student)
            ->get(route('bookings.waiver', $booking))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Booking/Waiver')
                ->where('participant.id', $primary->id)
            );

        $sign = fn (int $participantId) => $this->actingAs($this->student)->post(
            route('bookings.store-waiver', $booking),
            [
                'signed_by' => 'Signer',
                'signature' => 'data:image/png;base64,AAAA',
                'agreement' => true,
                'participant_id' => $participantId,
            ]
        );

        // Signing the primary loops back for the companion, not to payment.
        $sign($primary->id)->assertRedirect(route('bookings.waiver', $booking));

        $this->actingAs($this->student)
            ->get(route('bookings.waiver', $booking))
            ->assertInertia(fn ($page) => $page->where('participant.id', $companion->id));

        // Signing the companion completes the flow.
        $sign($companion->id)->assertRedirect(route('bookings.payment', $booking));

        $this->assertTrue(app(\App\Services\WaiverService::class)->hasRequiredWaivers($booking->fresh()));
    }

    /** @test */
    public function payment_is_blocked_until_every_participant_has_signed()
    {
        $payload = $this->basePayload([
            'participants' => [
                ['full_name' => 'Primary Surfer', 'age' => 30, 'height' => 175, 'weight' => 70, 'skill_level' => 'intermediate', 'has_board' => false],
                ['full_name' => 'Companion One', 'age' => 28, 'height' => 160, 'weight' => 55, 'skill_level' => 'beginner', 'has_board' => true],
            ],
        ]);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor->id), $payload)
            ->assertRedirect();

        $booking = Booking::with('participants')->latest('id')->firstOrFail();
        $primary = $booking->participants[0];

        // Sign only the primary, then try to pay.
        $this->actingAs($this->student)->post(
            route('bookings.store-waiver', $booking),
            [
                'signed_by' => 'Signer',
                'signature' => 'data:image/png;base64,AAAA',
                'agreement' => true,
                'participant_id' => $primary->id,
            ]
        );

        $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking), ['payment_method' => 'cash'])
            ->assertRedirect(route('bookings.waiver', $booking));

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }
}
