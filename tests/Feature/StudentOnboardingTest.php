<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        $this->student = User::factory()->create(['role' => 'student']);
        $this->student->assignRole('student');
        $this->student->studentProfile()->create(['skill_level' => 'beginner']);
    }

    private function profile(): \App\Models\StudentProfile
    {
        return $this->student->studentProfile->fresh();
    }

    /** @test */
    public function the_form_renders_for_a_student()
    {
        $this->actingAs($this->student)
            ->get(route('student.onboarding'))
            ->assertOk();
    }

    /** @test */
    public function saving_details_stores_them_and_marks_the_profile_complete()
    {
        $this->actingAs($this->student)
            ->post(route('student.onboarding.store'), [
                'date_of_birth' => now()->subYears(28)->format('Y-m-d'),
                'height' => 175,
                'weight' => 70,
                'has_board' => false,
                'emergency_contact_name' => 'Maria Reyes',
                'emergency_contact_phone' => '+63 917 555 1234',
                'medical_info' => 'Mild asthma',
            ])
            ->assertRedirect(route('student.dashboard'));

        $profile = $this->profile();

        $this->assertSame(28, $profile->age);
        $this->assertSame('175.00', $profile->height);
        $this->assertSame('70.00', $profile->weight);
        $this->assertSame('Maria Reyes', $profile->emergency_contact_name);
        $this->assertNotNull($profile->onboarding_completed_at);
        $this->assertTrue($profile->hasBookingDetails());
    }

    /** @test */
    public function age_is_derived_from_date_of_birth_and_does_not_go_stale()
    {
        // Born exactly 10 years ago: still a minor under the 5-12 safety rule.
        $profile = $this->profile();
        $profile->forceFill(['date_of_birth' => now()->subYears(10)])->save();

        $this->assertSame(10, $profile->fresh()->age);

        // Move the clock forward two years. A stored age column would still say
        // 10; the derived age must not.
        $this->travel(2)->years();

        $this->assertSame(12, $profile->fresh()->age);
    }

    /** @test */
    public function a_birth_date_under_five_is_rejected()
    {
        $this->actingAs($this->student)
            ->post(route('student.onboarding.store'), [
                'date_of_birth' => now()->subYears(3)->format('Y-m-d'),
                'has_board' => false,
            ])
            ->assertSessionHasErrors('date_of_birth');
    }

    /** @test */
    public function a_future_birth_date_is_rejected()
    {
        $this->actingAs($this->student)
            ->post(route('student.onboarding.store'), [
                'date_of_birth' => now()->addYear()->format('Y-m-d'),
                'has_board' => false,
            ])
            ->assertSessionHasErrors('date_of_birth');
    }

    /** @test */
    public function a_student_with_their_own_board_does_not_need_measurements()
    {
        $this->actingAs($this->student)
            ->post(route('student.onboarding.store'), [
                'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
                'has_board' => true,
                'height' => null,
                'weight' => null,
            ])
            ->assertSessionHasNoErrors();

        $profile = $this->profile();

        $this->assertNull($profile->height);
        $this->assertNull($profile->weight);
        // Own board means measurements are not needed for completeness.
        $this->assertTrue($profile->hasBookingDetails());
    }

    /** @test */
    public function skipping_is_recorded_and_stops_the_prompt()
    {
        $this->actingAs($this->student)
            ->post(route('student.onboarding.skip'))
            ->assertRedirect(route('student.dashboard'));

        $profile = $this->profile();

        $this->assertNotNull($profile->onboarding_skipped_at);
        $this->assertFalse($profile->needsOnboarding());
    }

    /** @test */
    public function the_prompt_is_not_shown_again_once_completed()
    {
        $this->actingAs($this->student)->post(route('student.onboarding.store'), [
            'date_of_birth' => now()->subYears(25)->format('Y-m-d'),
            'height' => 170,
            'weight' => 65,
            'has_board' => false,
        ]);

        $this->assertFalse($this->profile()->needsOnboarding());
    }

    /** @test */
    public function the_booking_prefill_only_contains_supplied_values()
    {
        $this->actingAs($this->student)->post(route('student.onboarding.store'), [
            'date_of_birth' => now()->subYears(25)->format('Y-m-d'),
            'height' => 170,
            'weight' => 65,
            'has_board' => true,
        ]);

        $prefill = $this->profile()->bookingPrefill();

        $this->assertSame(25, $prefill['student_age']);
        $this->assertSame(170.0, $prefill['height']);
        $this->assertTrue($prefill['has_board']);

        // Not supplied, so absent rather than a null that would overwrite a
        // useful form default.
        $this->assertArrayNotHasKey('emergency_contact_name', $prefill);
        $this->assertArrayNotHasKey('medical_info', $prefill);
    }

    /** @test */
    public function the_booking_form_receives_the_prefill()
    {
        $this->actingAs($this->student)->post(route('student.onboarding.store'), [
            'date_of_birth' => now()->subYears(33)->format('Y-m-d'),
            'height' => 182,
            'weight' => 78,
            'has_board' => false,
            'medical_info' => 'Knee injury in 2020',
        ]);

        $instructor = User::factory()->create(['role' => 'instructor']);
        $instructor->assignRole('instructor');
        $instructor->instructorProfile()->create([
            'level' => 2,
            'status' => 'active',
            'rate_per_hour' => 700,
        ]);

        $this->actingAs($this->student)
            ->get(route('bookings.create', $instructor->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Booking/Create')
                ->where('prefill.student_age', 33)
                ->where('prefill.height', 182)
                ->where('prefill.medical_info', 'Knee injury in 2020')
            );
    }

    /** @test */
    public function an_open_redirect_in_the_intended_field_is_ignored()
    {
        $this->actingAs($this->student)
            ->post(route('student.onboarding.store'), [
                'date_of_birth' => now()->subYears(25)->format('Y-m-d'),
                'height' => 170,
                'weight' => 65,
                'has_board' => false,
                'intended' => 'https://evil.example.com/steal',
            ])
            ->assertRedirect(route('student.dashboard'));

        $this->actingAs($this->student)
            ->post(route('student.onboarding.store'), [
                'date_of_birth' => now()->subYears(25)->format('Y-m-d'),
                'height' => 170,
                'weight' => 65,
                'has_board' => false,
                'intended' => '//evil.example.com',
            ])
            ->assertRedirect(route('student.dashboard'));
    }

    /** @test */
    public function a_local_intended_path_is_honoured()
    {
        $this->actingAs($this->student)
            ->post(route('student.onboarding.store'), [
                'date_of_birth' => now()->subYears(25)->format('Y-m-d'),
                'height' => 170,
                'weight' => 65,
                'has_board' => false,
                'intended' => '/bookings/create/3',
            ])
            ->assertRedirect('/bookings/create/3');
    }
}
