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
        $dob = now()->subYears(28)->format('Y-m-d');

        $this->actingAs($this->student)
            ->post(route('student.onboarding.store'), [
                'phone' => '+63 917 000 9999',
                'date_of_birth' => $dob,
                'country' => '  Philippines  ',
                'height' => 175,
                'weight' => 70,
                'has_board' => false,
                'emergency_contact_name' => 'Maria Reyes',
                'emergency_contact_phone' => '+63 917 555 1234',
                'medical_info' => 'Mild asthma',
            ])
            ->assertRedirect(route('student.dashboard'));

        $profile = $this->profile();

        $this->assertSame('+63 917 000 9999', $this->student->fresh()->phone);
        $this->assertSame($dob, $profile->birthday->format('Y-m-d'));
        $this->assertSame('Philippines', $profile->country);
        $this->assertSame(28, $profile->age);
        $this->assertSame('175.00', $profile->height);
        $this->assertSame('70.00', $profile->weight);
        $this->assertSame('Maria Reyes', $profile->emergency_contact_name);
        $this->assertNull($profile->medical_info);
        $this->assertNotNull($profile->onboarding_completed_at);
        $this->assertTrue($profile->hasBookingDetails());
    }

    /** @test */
    public function age_is_derived_from_birthday_and_does_not_go_stale()
    {
        // Born exactly 10 years ago: still a minor under the 5-12 safety rule.
        $profile = $this->profile();
        $profile->forceFill(['birthday' => now()->subYears(10)])->save();

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
            'phone' => '+63 917 888 4321',
            'date_of_birth' => now()->subYears(33)->format('Y-m-d'),
            'height' => 182,
            'weight' => 78,
            'has_board' => false,
            'emergency_contact_name' => 'Elena Cruz',
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
                ->where('prefill.emergency_contact_name', 'Elena Cruz')
            );
    }

    /** @test */
    public function onboarding_exposes_student_phone_and_omits_medical_info()
    {
        $this->student->update(['phone' => '+63 917 111 2222']);

        $this->actingAs($this->student)
            ->get(route('student.onboarding'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/Onboarding')
                ->where('profile.phone', '+63 917 111 2222')
                ->missing('profile.medical_info')
            );
    }

    /** @test */
    public function an_invalid_phone_number_is_rejected()
    {
        $this->actingAs($this->student)
            ->post(route('student.onboarding.store'), [
                'phone' => 'not-a-phone!',
                'has_board' => true,
            ])
            ->assertSessionHasErrors('phone');
    }

    /** @test */
    public function a_fresh_student_is_prompted_on_their_first_visit_to_the_dashboard()
    {
        // Regression: the prompt originally lived in the login controller, so a
        // brand-new signup - which goes register -> verify email -> /dashboard
        // and never touches login - saw no onboarding at all. It only appeared
        // on a *second* login, which is what the manual test did.
        //
        // /dashboard forwards to the role dashboard; the prompt fires there.
        // Both links in that chain must end at onboarding.
        $this->actingAs($this->student)
            ->get(route('dashboard'))
            ->assertRedirect(route('student.dashboard'));

        $this->actingAs($this->student)
            ->get(route('student.dashboard'))
            ->assertRedirectContains(route('student.onboarding'));
    }

    /** @test */
    public function the_prompt_carries_the_intended_destination()
    {
        // The middleware remembers where the student was heading so they can be
        // returned there after saving or skipping.
        $response = $this->actingAs($this->student)->get(route('student.dashboard'));

        $response->assertRedirect();

        $location = $response->headers->get('Location');

        $this->assertStringContainsString('/student/welcome', $location);
        $this->assertStringContainsString('intended=', $location);
        $this->assertStringContainsString(
            urlencode(route('student.dashboard')),
            $location,
            'The intended destination should survive the trip through onboarding.'
        );
    }

    /** @test */
    public function the_prompt_does_not_loop_on_the_form_itself()
    {
        $this->actingAs($this->student)
            ->get(route('student.onboarding'))
            ->assertOk();
    }

    /** @test */
    public function the_prompt_stops_once_the_details_are_saved()
    {
        $this->actingAs($this->student)->post(route('student.onboarding.store'), [
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'height' => 170,
            'weight' => 65,
            'has_board' => false,
        ]);

        $this->actingAs($this->student)
            ->get(route('student.dashboard'))
            ->assertOk();
    }

    /** @test */
    public function the_prompt_stops_after_skipping()
    {
        $this->actingAs($this->student)->post(route('student.onboarding.skip'));

        $this->actingAs($this->student)
            ->get(route('student.dashboard'))
            ->assertOk();
    }

    /** @test */
    public function instructors_are_never_prompted()
    {
        $instructor = User::factory()->create(['role' => 'instructor']);
        $instructor->assignRole('instructor');

        $this->actingAs($instructor)
            ->get(route('student.dashboard'))
            ->assertForbidden();
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

    /** @test */
    public function onboarding_exposes_profile_birthday_and_country()
    {
        $this->student->studentProfile->update([
            'birthday' => '1999-05-15',
            'country' => 'Australia',
        ]);

        $this->actingAs($this->student)
            ->get(route('student.onboarding'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/Onboarding')
                ->where('profile.date_of_birth', '1999-05-15')
                ->where('profile.country', 'Australia')
            );
    }

    /** @test */
    public function country_longer_than_100_characters_is_rejected()
    {
        $this->actingAs($this->student)
            ->post(route('student.onboarding.store'), [
                'country' => str_repeat('a', 101),
                'has_board' => true,
            ])
            ->assertSessionHasErrors('country');
    }
}
