<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\InstructorStatus;
use App\Models\InstructorProfile;
use App\Models\User;
use App\Services\FileScanService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
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
            'terms_accepted' => true,
        ])->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'newinst@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('instructor'));

        $user->markEmailAsVerified();

        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect(route('instructor.onboarding'));
        $this->actingAs($user->fresh())->get('/instructor/onboarding')->assertOk();
    }

    public function test_instructor_can_add_a_certificate_from_the_profile_page(): void
    {
        Storage::fake('local');

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

        Storage::disk('local')->assertExists($user->certificates()->first()->file_path);
    }

    public function test_onboarding_with_certificate_succeeds(): void
    {
        Storage::fake('local');
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
        Storage::disk('local')->assertExists($user->certificates()->first()->file_path);

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
        $this->actingAs($user)->get('/instructor/pending')->assertRedirect(route('instructor.onboarding'));
    }

    public function test_unverified_email_instructor_is_redirected_to_verification_notice(): void
    {
        $user = User::factory()->instructor()->unverified()->create(['email' => 'unverified@example.test']);
        $user->assignRole('instructor');

        $this->actingAs($user)->get('/instructor/onboarding')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get('/')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get('/instructors')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get('/profile')->assertRedirect(route('verification.notice'));
    }

    public function test_onboarding_page_reports_real_upload_limits_and_existing_state(): void
    {
        $user = $this->instructor();
        InstructorProfile::create([
            'user_id' => $user->id,
            'bio' => 'Existing bio',
            'level' => 3,
            'status' => 'rejected',
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
        Storage::fake('local');
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

    public function test_onboarding_omits_latitude_and_longitude_and_saves_area_and_meeting_point(): void
    {
        Storage::fake('local');
        $user = $this->instructor('location@example.test');
        InstructorProfile::create([
            'user_id' => $user->id,
            'bio' => 'Existing bio',
            'level' => 2,
            'status' => 'pending_verification',
            'rate_per_hour' => 800,
            'area' => 'General Luna',
            'meeting_point' => 'Cloud 9 tower',
            'latitude' => 9.8123456,
            'longitude' => 126.1654321,
        ]);

        $this->actingAs($user)
            ->get('/instructor/onboarding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Instructor/Onboarding')
                ->where('profile.area', 'General Luna')
                ->where('profile.meeting_point', 'Cloud 9 tower')
                ->missing('profile.latitude')
                ->missing('profile.longitude')
            );

        $this->actingAs($user)
            ->post('/instructor/onboarding', [
                'bio' => 'Updated surf bio.',
                'level' => 2,
                'rate_per_hour' => 800,
                'area' => 'Catangnan',
                'meeting_point' => 'Cloud 9 boardwalk',
                'latitude' => 9.9000000,
                'longitude' => 126.2000000,
                'certificates' => [UploadedFile::fake()->create('sisa_certificate.pdf', 100, 'application/pdf')],
            ])
            ->assertRedirect(route('instructor.pending'));

        $profile = $user->instructorProfile()->firstOrFail();
        $this->assertSame('Catangnan', $profile->area);
        $this->assertSame('Cloud 9 boardwalk', $profile->meeting_point);
        $this->assertEquals(9.8123456, (float) $profile->latitude);
        $this->assertEquals(126.1654321, (float) $profile->longitude);
    }

    public function test_level_3_onboarding_requires_at_least_1500_coaching_rate(): void
    {
        Storage::fake('local');
        $user = $this->instructor('level3min@example.test');

        $this->actingAs($user)
            ->from('/instructor/onboarding')
            ->post('/instructor/onboarding', [
                'bio' => 'Master instructor.',
                'level' => 3,
                'rate_per_hour' => 800,
                'guiding_rate' => 1100,
                'coaching_rate' => 1400,
                'certificates' => [UploadedFile::fake()->create('isa_cert.pdf', 100, 'application/pdf')],
            ])
            ->assertSessionHasErrors('coaching_rate')
            ->assertRedirect('/instructor/onboarding');

        $this->assertDatabaseCount('instructor_profiles', 0);

        $this->actingAs($user)
            ->post('/instructor/onboarding', [
                'bio' => 'Master instructor.',
                'level' => 3,
                'rate_per_hour' => 800,
                'guiding_rate' => 1100,
                'coaching_rate' => 1600,
                'certificates' => [UploadedFile::fake()->create('isa_cert.pdf', 100, 'application/pdf')],
            ])
            ->assertRedirect(route('instructor.pending'));

        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $user->id,
            'level' => 3,
            'rate_per_hour' => 800,
            'guiding_rate' => 1100,
            'coaching_rate' => 1600,
            'status' => InstructorStatus::PendingVerification->value,
        ]);
    }

    public function test_unonboarded_instructor_cannot_bypass_onboarding_across_routes(): void
    {
        Storage::fake('local');

        $user = $this->instructor('unonboarded@example.test');

        $activeInstructor = $this->instructor('verified-other@example.test');
        $activeInstructor->instructorProfile()->create([
            'bio' => 'Active instructor',
            'level' => 2,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => 800,
        ]);
        $activeInstructor->certificates()->create([
            'type' => CertificateType::SISA->value,
            'file_path' => 'certificates/active.pdf',
            'status' => CertificateStatus::Verified->value,
            'verified_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        $this->actingAs($user)->get('/')->assertRedirect(route('instructor.onboarding'));
        $this->actingAs($user)->get(route('instructors.search'))->assertRedirect(route('instructor.onboarding'));
        $this->actingAs($user)->get(route('instructors.profile', $activeInstructor))->assertRedirect(route('instructor.onboarding'));
        $this->actingAs($user)->get(route('surf-spots.index'))->assertRedirect(route('instructor.onboarding'));
        $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('instructor.onboarding'));
        $this->actingAs($user)->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ])->assertRedirect(route('instructor.onboarding'));
        $this->actingAs($user)->delete(route('profile.destroy'), [
            'password' => 'password',
        ])->assertRedirect(route('instructor.onboarding'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        $this->actingAs($user)->get(route('instructor.pending'))->assertRedirect(route('instructor.onboarding'));
        $this->actingAs($user)->get(route('instructor.dashboard'))->assertRedirect(route('instructor.onboarding'));
        $this->actingAs($user)->get(route('instructor.availability.index'))->assertRedirect(route('instructor.onboarding'));
        $this->actingAs($user)->post(route('instructor.certificates.store'), [
            'certificates' => [UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf')],
        ])->assertRedirect(route('instructor.onboarding'));
        $this->assertDatabaseCount('certificates', 1);

        // Role boundaries stay 403
        $this->actingAs($user)->get(route('admin.instructors.index'))->assertForbidden();
        $this->actingAs($user)->get(route('bookings.create', $activeInstructor))->assertForbidden();

        // Logout remains reachable
        $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
    }

    public function test_pending_instructor_is_restricted_to_pending_and_cannot_resubmit_onboarding(): void
    {
        Storage::fake('local');

        $user = $this->instructor('pending@example.test');
        $user->instructorProfile()->create([
            'bio' => 'Pending instructor',
            'level' => 1,
            'status' => InstructorStatus::PendingVerification->value,
            'rate_per_hour' => 600,
        ]);
        $user->certificates()->create([
            'type' => CertificateType::SISA->value,
            'file_path' => 'certificates/pending.pdf',
            'status' => CertificateStatus::PendingVerification->value,
        ]);

        $this->actingAs($user)->get('/')->assertRedirect(route('instructor.pending'));
        $this->actingAs($user)->get(route('instructors.search'))->assertRedirect(route('instructor.pending'));
        $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('instructor.pending'));
        $this->actingAs($user)->get(route('instructor.onboarding'))->assertRedirect(route('instructor.pending'));
        $this->actingAs($user)->get(route('instructor.dashboard'))->assertRedirect(route('instructor.pending'));

        $this->actingAs($user)->post(route('instructor.onboarding.store'), [
            'bio' => 'Attempted escalation',
            'level' => 3,
            'rate_per_hour' => 1500,
            'certificates' => [UploadedFile::fake()->create('extra.pdf', 100, 'application/pdf')],
        ])->assertRedirect(route('instructor.pending'));

        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $user->id,
            'level' => 1,
            'status' => InstructorStatus::PendingVerification->value,
        ]);

        $this->actingAs($user)->get(route('instructor.pending'))->assertOk();
        $this->actingAs($user)->post(route('instructor.certificates.store'), [
            'certificates' => [UploadedFile::fake()->create('bls_extra.pdf', 100, 'application/pdf')],
        ])->assertRedirect();
        $this->assertSame(2, $user->certificates()->count());

        $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
    }

    public function test_suspended_instructor_cannot_escape_suspension_screen(): void
    {
        $user = $this->instructor('suspended@example.test');
        $user->instructorProfile()->create([
            'bio' => 'Suspended instructor',
            'level' => 2,
            'status' => InstructorStatus::Suspended->value,
            'rate_per_hour' => 800,
            'suspended_until' => now()->addWeeks(2),
            'suspension_reason' => 'Three active strikes.',
        ]);

        $this->actingAs($user)->get('/')->assertRedirect(route('instructor.suspended'));
        $this->actingAs($user)->get(route('instructors.search'))->assertRedirect(route('instructor.suspended'));
        $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('instructor.suspended'));
        $this->actingAs($user)->delete(route('profile.destroy'), [
            'password' => 'password',
        ])->assertRedirect(route('instructor.suspended'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        $this->actingAs($user)->get(route('instructor.dashboard'))->assertRedirect(route('instructor.suspended'));
        $this->actingAs($user)->get(route('instructor.onboarding'))->assertRedirect(route('instructor.suspended'));
        $this->actingAs($user)->get(route('instructor.pending'))->assertRedirect(route('instructor.suspended'));

        $this->actingAs($user)->get(route('instructor.suspended'))->assertOk();
        $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
    }

    public function test_verified_active_instructor_cannot_reenter_onboarding_pending_or_suspended_screens(): void
    {
        Storage::fake('local');

        $user = $this->instructor('active@example.test');
        $user->instructorProfile()->create([
            'bio' => 'Active instructor',
            'level' => 1,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => 600,
        ]);

        $this->actingAs($user)->get(route('instructor.onboarding'))->assertRedirect(route('instructor.dashboard'));
        $this->actingAs($user)->get(route('instructor.pending'))->assertRedirect(route('instructor.dashboard'));
        $this->actingAs($user)->get(route('instructor.suspended'))->assertRedirect(route('instructor.dashboard'));

        $this->actingAs($user)->post(route('instructor.onboarding.store'), [
            'bio' => 'Attempted level overwrite',
            'level' => 3,
            'rate_per_hour' => 1500,
            'certificates' => [UploadedFile::fake()->create('fake.pdf', 100, 'application/pdf')],
        ])->assertRedirect(route('instructor.dashboard'));

        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $user->id,
            'level' => 1,
            'status' => InstructorStatus::Active->value,
        ]);
    }

    public function test_vacation_mode_keeps_dashboard_and_availability_accessible_while_non_bookable(): void
    {
        $user = $this->instructor('vacation@example.test');
        $user->instructorProfile()->create([
            'bio' => 'Active instructor',
            'level' => 2,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => 800,
        ]);

        $this->actingAs($user)->post(route('instructor.availability.toggle-status'))->assertRedirect();
        $user->refresh();

        $this->assertSame(InstructorStatus::Inactive, $user->instructorProfile->status);
        $this->assertFalse($user->isBookable());

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('instructor.dashboard'));
        $this->actingAs($user)->get(route('instructor.dashboard'))->assertOk();
        $this->actingAs($user)->get(route('instructor.availability.index'))->assertOk();

        // Can toggle Vacation Mode back OFF
        $this->actingAs($user)->post(route('instructor.availability.toggle-status'))->assertRedirect();
        $user->refresh();

        $this->assertSame(InstructorStatus::Active, $user->instructorProfile->status);
        $this->assertTrue($user->isBookable());
    }

    public function test_admin_rejection_sends_instructor_back_to_onboarding_for_resubmission(): void
    {
        Storage::fake('local');

        $admin = User::factory()->admin()->create();
        $admin->assignRole('admin');

        $user = $this->instructor('rejected@example.test');
        $user->instructorProfile()->create([
            'bio' => 'Pending instructor',
            'level' => 1,
            'status' => InstructorStatus::PendingVerification->value,
            'rate_per_hour' => 600,
        ]);
        $user->certificates()->create([
            'type' => CertificateType::SISA->value,
            'file_path' => 'certificates/initial.pdf',
            'status' => CertificateStatus::PendingVerification->value,
        ]);

        $this->actingAs($user)->get(route('instructor.pending'))->assertOk();

        $this->actingAs($admin)->post(route('admin.instructors.reject', $user), [
            'reason' => 'Certificate scan is unreadable.',
        ])->assertRedirect();

        $profile = $user->instructorProfile->fresh();
        $this->assertSame(InstructorStatus::Rejected, $profile->status);
        $this->assertSame('Certificate scan is unreadable.', $profile->rejection_reason);
        $this->assertNotNull($profile->rejected_at);

        $this->assertDatabaseHas('instructor_status_logs', [
            'instructor_id' => $user->id,
            'from_status' => InstructorStatus::PendingVerification->value,
            'to_status' => InstructorStatus::Rejected->value,
            'reason' => 'Certificate scan is unreadable.',
            'changed_by' => $admin->id,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('instructor.onboarding'));
        $this->actingAs($user)->get(route('instructor.onboarding'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Instructor/Onboarding')
                ->where('profile.status', InstructorStatus::Rejected->value)
                ->where('profile.rejection_reason', 'Certificate scan is unreadable.')
                ->where('profile.rejected_at', fn ($value) => is_string($value) && $value !== '')
            );

        $notification = $user->notifications()->latest()->first();
        $this->assertNotNull($notification);
        $this->assertSame('instructor_rejected', $notification->data['type']);
        $this->assertSame('Certificate scan is unreadable.', $notification->data['reason']);
        $this->assertSame(
            'Your instructor application was not approved. Reason: Certificate scan is unreadable.',
            $notification->data['message']
        );
        $this->assertSame(route('instructor.onboarding'), $notification->data['url']);

        $this->actingAs($user)->post(route('instructor.onboarding.store'), [
            'bio' => 'Updated bio with clear certificate scan.',
            'level' => 1,
            'rate_per_hour' => 650,
            'certificates' => [UploadedFile::fake()->create('sisa_clear.pdf', 150, 'application/pdf')],
        ])->assertRedirect(route('instructor.pending'));

        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $user->id,
            'status' => InstructorStatus::PendingVerification->value,
            'rate_per_hour' => 650,
            'rejection_reason' => null,
            'rejected_at' => null,
        ]);
    }

    public function test_failed_file_scan_does_not_leave_a_partial_instructor_profile(): void
    {
        Storage::fake('local');

        $this->mock(FileScanService::class, function ($mock) {
            $mock->shouldReceive('assertSafe')
                ->once()
                ->andThrow(new \RuntimeException('The file failed the malware scan.'));
        });

        $user = $this->instructor('scanfail@example.test');

        $this->actingAs($user)
            ->from('/instructor/onboarding')
            ->post('/instructor/onboarding', [
                'bio' => 'Certified surf instructor.',
                'level' => 1,
                'rate_per_hour' => 600,
                'certificates' => [UploadedFile::fake()->create('infected.pdf', 100, 'application/pdf')],
            ])
            ->assertSessionHasErrors('certificates')
            ->assertRedirect('/instructor/onboarding');

        $this->assertDatabaseCount('instructor_profiles', 0);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('certificates'));
    }

    public function test_register_page_honors_role_query_parameter(): void
    {
        $this->get(route('register', ['role' => 'instructor']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/Register')
                ->where('defaultRole', 'instructor')
            );

        $this->get(route('register', ['role' => 'admin']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/Register')
                ->where('defaultRole', 'student')
            );
    }

    public function test_onboarding_validates_and_persists_birthday(): void
    {
        Storage::fake('local');
        $user = $this->instructor('birthday-onboard@example.test');

        $this->actingAs($user)
            ->from('/instructor/onboarding')
            ->post('/instructor/onboarding', [
                'bio' => 'Young applicant',
                'birthday' => now()->subYears(17)->toDateString(),
                'level' => 1,
                'rate_per_hour' => 600,
                'certificates' => [UploadedFile::fake()->create('sisa_cert.pdf', 100, 'application/pdf')],
            ])
            ->assertSessionHasErrors('birthday');

        $validBirthday = '2000-04-12';
        $this->actingAs($user)
            ->post('/instructor/onboarding', [
                'bio' => 'Eligible instructor',
                'birthday' => $validBirthday,
                'level' => 1,
                'rate_per_hour' => 600,
                'certificates' => [UploadedFile::fake()->create('sisa_cert.pdf', 100, 'application/pdf')],
            ])
            ->assertRedirect(route('instructor.pending'));

        $this->assertSame($validBirthday, $user->instructorProfile()->firstOrFail()->birthday?->format('Y-m-d'));
    }

    public function test_instructor_profile_update_validates_and_saves_birthday(): void
    {
        $user = $this->instructor('birthday-profile@example.test');
        $user->instructorProfile()->create([
            'bio' => 'Active instructor',
            'level' => 1,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => 600,
        ]);

        $this->actingAs($user)
            ->from(route('instructor.profile.edit'))
            ->patch(route('instructor.profile.update'), [
                'name' => $user->name,
                'bio' => 'Active instructor',
                'birthday' => now()->subYears(16)->toDateString(),
                'offered_services' => ['teaching'],
                'rate_per_hour' => 600,
            ])
            ->assertSessionHasErrors('birthday');

        $this->actingAs($user)
            ->patch(route('instructor.profile.update'), [
                'name' => $user->name,
                'bio' => 'Active instructor',
                'birthday' => '1997-08-24',
                'offered_services' => ['teaching'],
                'rate_per_hour' => 600,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('1997-08-24', $user->instructorProfile()->firstOrFail()->birthday?->format('Y-m-d'));
    }
}