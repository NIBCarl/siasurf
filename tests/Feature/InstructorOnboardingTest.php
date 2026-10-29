<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\InstructorProfile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstructorOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function instructor(string $email = 'inst@example.test'): User
    {
        $user = User::factory()->instructor()->create(['email' => $email]);
        $user->assignRole('instructor');

        return $user;
    }

    public function test_registration_lands_on_onboarding(): void
    {
        $this->post('/register', [
            'name' => 'New Instructor',
            'email' => 'newinst@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'instructor',
        ])->assertRedirect('/dashboard');

        $this->assertTrue(User::where('email', 'newinst@example.test')->first()->hasRole('instructor'));

        $this->get('/dashboard')->assertRedirect(route('instructor.onboarding'));
        $this->get('/instructor/onboarding')->assertOk();
    }

    public function test_instructor_can_add_a_certificate_from_the_profile_page(): void
    {
        Storage::fake('public');

        $user = $this->instructor('extra-cert@example.test');
        $user->instructorProfile()->create([
            'bio' => 'Active instructor adding a refresher certificate.',
            'level' => 1,
            'status' => 'active',
            'rate_per_hour' => 600,
        ]);

        $response = $this->actingAs($user)->post(route('instructor.certificates.store'), [
            'certificates' => [UploadedFile::fake()->create('bls_refresher.pdf', 300, 'application/pdf')],
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('certificates', [
            'user_id' => $user->id,
            'type' => 'bls',
            'status' => 'pending_verification',
        ]);

        Storage::disk('public')->assertExists($user->certificates()->first()->file_path);
    }

    public function test_onboarding_with_certificate_succeeds(): void
    {
        Storage::fake('public');
        $user = $this->instructor();

        $this->actingAs($user)
            ->post('/instructor/onboarding', [
                'bio' => 'Certified surf instructor, 5 years in Siargao.',
                'level' => 1,
                'rate_per_hour' => 600,
                'certificates' => [UploadedFile::fake()->create('sisa_certificate.pdf', 500, 'application/pdf')],
            ])
            ->assertRedirect(route('instructor.pending'));

        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $user->id,
            'status' => 'pending_verification',
        ]);
        $this->assertDatabaseCount('certificates', 1);
        Storage::disk('public')->assertExists($user->certificates()->first()->file_path);

        // WITH a certificate the dashboard no longer bounces back to onboarding
        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect(route('instructor.pending'));
    }

    public function test_onboarding_without_certificate_bounces_back_to_onboarding(): void
    {
        $user = $this->instructor();

        $this->actingAs($user)
            ->from('/instructor/onboarding')
            ->post('/instructor/onboarding', [
                'bio' => 'Certified surf instructor, 5 years in Siargao.',
                'level' => 1,
                'rate_per_hour' => 600,
            ])
            ->assertSessionHasErrors('certificates')
            ->assertRedirect('/instructor/onboarding');
    }

    public function test_bio_longer_than_1000_chars_bounces_back_to_onboarding(): void
    {
        $user = $this->instructor();

        $this->actingAs($user)
            ->from('/instructor/onboarding')
            ->post('/instructor/onboarding', [
                'bio' => str_repeat('word ', 250), // 1250 chars
                'level' => 1,
                'rate_per_hour' => 600,
                'certificates' => [UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf')],
            ])
            ->assertSessionHasErrors('bio')
            ->assertRedirect('/instructor/onboarding');
    }

    public function test_rate_below_minimum_bounces_back_to_onboarding(): void
    {
        $user = $this->instructor();

        $this->actingAs($user)
            ->from('/instructor/onboarding')
            ->post('/instructor/onboarding', [
                'bio' => 'Certified surf instructor.',
                'level' => 1,
                'rate_per_hour' => 500,
                'certificates' => [UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf')],
            ])
            ->assertSessionHasErrors('rate_per_hour')
            ->assertRedirect('/instructor/onboarding');
    }

    public function test_admin_created_instructor_without_certificates_loops_to_onboarding(): void
    {
        // Mirrors Admin\InstructorController@store: profile created, NO certificates
        $user = $this->instructor('adminmade@example.test');
        InstructorProfile::create([
            'user_id' => $user->id,
            'bio' => 'Seeded by admin',
            'level' => 1,
            'status' => 'pending_verification',
            'rate_per_hour' => 600,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('instructor.onboarding'));
        $this->actingAs($user)->get('/instructor/pending')->assertOk();
    }

    public function test_unverified_email_is_not_enforced_on_instructor_routes(): void
    {
        // User model does NOT implement MustVerifyEmail, so the 'verified'
        // middleware is a no-op and email_verified_at = null does not block anyone.
        $user = User::factory()->instructor()->unverified()->create(['email' => 'unverified@example.test']);
        $user->assignRole('instructor');

        $this->actingAs($user)->get('/instructor/onboarding')->assertOk();
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('instructor.onboarding'));
    }

    public function test_onboarding_page_reports_real_upload_limits_and_existing_state(): void
    {
        $user = $this->instructor();
        InstructorProfile::create([
            'user_id' => $user->id,
            'bio' => 'Existing bio',
            'level' => 3,
            'status' => 'pending_verification',
            'rate_per_hour' => 1500,
        ]);
        $user->certificates()->create([
            'type' => 'sisa',
            'file_path' => 'certificates/example.pdf',
            'status' => 'pending_verification',
        ]);

        $response = $this->actingAs($user)->get('/instructor/onboarding');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Instructor/Onboarding')
            ->where('uploadedCertificates', 1)
            ->where('profile.level', 3)
            ->where('profile.bio', 'Existing bio')
            ->where('profile.rate_per_hour', 1500)
        );

        // The client must be able to see the true server limits
        $uploads = $response->viewData('page')['props']['uploads'];
        $this->assertGreaterThanOrEqual(1, $uploads['maxFileMb']);
        $this->assertGreaterThanOrEqual($uploads['maxFileMb'], $uploads['maxRequestMb']);
    }

    public function test_dashboard_bounce_explains_missing_certificates(): void
    {
        $user = $this->instructor('nocerts@example.test');
        InstructorProfile::create([
            'user_id' => $user->id,
            'bio' => 'Profile without certificates',
            'level' => 1,
            'status' => 'pending_verification',
            'rate_per_hour' => 600,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('instructor.onboarding'))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'no certificate has been uploaded'));
    }

    public function test_certificate_type_is_detected_from_filename(): void
    {
        Storage::fake('public');
        $user = $this->instructor('typedetect@example.test');

        $this->actingAs($user)->post('/instructor/onboarding', [
            'bio' => 'Certified surf instructor.',
            'level' => 1,
            'rate_per_hour' => 600,
            'certificates' => [
                UploadedFile::fake()->create('sisa_certificate.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('bls_training.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('wasar_card.jpg', 100, 'image/jpeg'),
            ],
        ])->assertRedirect(route('instructor.pending'));

        $types = $user->certificates()->pluck('type')->map(fn ($t) => $t->value)->sort()->values()->all();

        // "sisa_certificate.pdf" must be SISA, not ISA (it contains the substring "isa")
        $this->assertSame(['bls', 'sisa', 'wasar'], $types);
    }

    public function test_validation_messages_are_actionable(): void
    {
        $user = $this->instructor('messages@example.test');

        $this->actingAs($user)
            ->from('/instructor/onboarding')
            ->post('/instructor/onboarding', [
                'bio' => 'Certified surf instructor.',
                'level' => 1,
                'rate_per_hour' => 600,
            ])
            ->assertSessionHasErrors([
                'certificates' => 'Attach at least one certificate file (PDF, JPG, or PNG) before submitting.',
            ]);
    }
}