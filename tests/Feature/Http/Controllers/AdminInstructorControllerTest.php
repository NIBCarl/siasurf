<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\InstructorStatus;
use App\Models\Certificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminInstructorControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        \Spatie\Permission\Models\Role::findOrCreate('student', 'web');
        \Spatie\Permission\Models\Role::findOrCreate('instructor', 'web');
        \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');
        
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('admin');

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');
        
        // Set up instructor profile
        $this->instructor->instructorProfile()->create([
            'status' => InstructorStatus::PendingVerification,
            'level' => 1,
            'rate_per_hour' => 1000
        ]);
    }

    #[Test]
    public function admin_can_view_instructors_list()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.instructors.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Instructors/Index')
            );
    }

    #[Test]
    public function non_admin_cannot_view_instructors_list()
    {
        $response = $this->actingAs($this->instructor)
            ->get(route('admin.instructors.index'));

        $response->assertForbidden();
    }

    #[Test]
    public function admin_can_register_new_instructor()
    {
        Storage::fake('local');

        $response = $this->actingAs($this->admin)
            ->post(route('admin.instructors.store'), [
                'name' => 'John Instructor',
                'email' => 'john@example.com',
                'password' => 'password123',
                'phone' => '+63 912 345 6789',
                'bio' => 'Experienced surf instructor',
                'level' => 2,
                'rate_per_hour' => 800,
            ]);

        $response->assertRedirect();
        
        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'role' => 'instructor',
        ]);

        $this->assertDatabaseHas('instructor_profiles', [
            'level' => 2,
            'status' => InstructorStatus::PendingVerification->value,
        ]);
    }

    #[Test]
    public function admin_can_verify_instructor()
    {
        $this->instructor->certificates()->create([
            'type' => CertificateType::SurfSkill,
            'file_path' => 'certificates/test.pdf',
            'status' => CertificateStatus::Verified,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.instructors.verify', $this->instructor->id));

        $response->assertRedirect();
        
        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $this->instructor->id,
            'status' => InstructorStatus::Active->value,
        ]);
    }

    #[Test]
    public function admin_can_suspend_instructor()
    {
        $this->instructor->instructorProfile()->update(['status' => InstructorStatus::Active]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.instructors.suspend', $this->instructor->id), [
                'reason' => 'Violation of safety protocols',
                'duration_days' => 30,
            ]);

        $response->assertRedirect();
        
        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $this->instructor->id,
            'status' => InstructorStatus::Suspended->value,
        ]);
    }

    #[Test]
    public function admin_can_reactivate_suspended_instructor()
    {
        $this->instructor->instructorProfile()->update([
            'status' => InstructorStatus::Suspended,
            'suspended_until' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.instructors.reactivate', $this->instructor->id));

        $response->assertRedirect();
        
        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $this->instructor->id,
            'status' => InstructorStatus::Active->value,
        ]);
    }

    #[Test]
    public function admin_can_upload_certificate()
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->admin)
            ->post(route('admin.certificates.store', $this->instructor->id), [
                'type' => 'bls',
                'certificate' => $file,
                'expires_at' => now()->addYear()->format('Y-m-d'),
            ]);

        $response->assertRedirect();
        
        $this->assertDatabaseHas('certificates', [
            'user_id' => $this->instructor->id,
            'type' => CertificateType::BLS->value,
            'status' => CertificateStatus::PendingVerification->value,
        ]);
    }

    #[Test]
    public function admin_can_verify_certificate()
    {
        Storage::fake('local');
        
        $certificate = Certificate::factory()->create([
            'user_id' => $this->instructor->id,
            'status' => CertificateStatus::PendingVerification,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.certificates.verify', $certificate->id), [
                'notes' => 'Certificate verified successfully',
            ]);

        $response->assertRedirect();
        
        $this->assertDatabaseHas('certificates', [
            'id' => $certificate->id,
            'status' => CertificateStatus::Verified->value,
        ]);
    }

    #[Test]
    public function admin_can_reject_certificate()
    {
        $certificate = Certificate::factory()->create([
            'user_id' => $this->instructor->id,
            'status' => CertificateStatus::PendingVerification,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.certificates.reject', $certificate->id), [
                'notes' => 'Certificate appears to be invalid',
            ]);

        $response->assertRedirect();
        
        $this->assertDatabaseHas('certificates', [
            'id' => $certificate->id,
            'status' => CertificateStatus::Rejected->value,
            'admin_notes' => 'Certificate appears to be invalid',
        ]);
    }

    #[Test]
    public function instructor_registration_requires_valid_email()
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.instructors.store'), [
                'name' => 'Test Instructor',
                'email' => 'invalid-email',
                'phone' => '+63 912 345 6789',
                'level' => 2,
            ]);

        $response->assertSessionHasErrors(['email']);
    }

    #[Test]
    public function instructor_registration_requires_unique_email()
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.instructors.store'), [
                'name' => 'Test Instructor',
                'email' => 'existing@example.com',
                'phone' => '+63 912 345 6789',
                'level' => 2,
            ]);

        $response->assertSessionHasErrors(['email']);
    }

    #[Test]
    public function activating_a_pending_instructor_via_edit_verifies_pending_certificates_and_shows_them_in_find_instructor()
    {
        Storage::fake('local');

        $cert = $this->instructor->certificates()->create([
            'type' => CertificateType::SurfSkill,
            'file_path' => 'certificates/pending.png',
            'status' => CertificateStatus::PendingVerification,
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.instructors.update', $this->instructor->id), [
                'name' => $this->instructor->name,
                'email' => $this->instructor->email,
                'level' => 1,
                'rate_per_hour' => 600,
                'status' => InstructorStatus::Active->value,
                'area' => 'General Luna',
            ])
            ->assertRedirect(route('admin.instructors.show', $this->instructor->id));

        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $this->instructor->id,
            'status' => InstructorStatus::Active->value,
        ]);
        $this->assertDatabaseHas('certificates', [
            'id' => $cert->id,
            'status' => CertificateStatus::Verified->value,
        ]);

        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');
        $student->studentProfile()->create([
            'skill_level' => 'beginner',
            'is_first_time' => true,
            'status' => 'active',
        ]);

        $this->actingAs($student)
            ->get(route('instructors.search'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('instructors.data', 1)
                ->where('instructors.data.0.id', $this->instructor->id)
            );
    }

    #[Test]
    public function verifying_a_pending_instructor_without_checkboxes_approves_pending_certificates_and_shows_them_in_find_instructor()
    {
        Storage::fake('local');

        $cert = $this->instructor->certificates()->create([
            'type' => CertificateType::SurfSkill,
            'file_path' => 'certificates/pending.png',
            'status' => CertificateStatus::PendingVerification,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.instructors.verify', $this->instructor->id))
            ->assertRedirect();

        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $this->instructor->id,
            'status' => InstructorStatus::Active->value,
        ]);
        $this->assertDatabaseHas('certificates', [
            'id' => $cert->id,
            'status' => CertificateStatus::Verified->value,
        ]);

        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');
        $student->studentProfile()->create([
            'skill_level' => 'beginner',
            'is_first_time' => true,
            'status' => 'active',
        ]);

        $this->actingAs($student)
            ->get(route('instructors.search'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('instructors.data', 1)
                ->where('instructors.data.0.id', $this->instructor->id)
            );
    }

    #[Test]
    public function activating_a_pending_instructor_with_no_certificates_is_rejected()
    {
        $this->actingAs($this->admin)
            ->from(route('admin.instructors.edit', $this->instructor->id))
            ->put(route('admin.instructors.update', $this->instructor->id), [
                'name' => $this->instructor->name,
                'email' => $this->instructor->email,
                'level' => 1,
                'rate_per_hour' => 600,
                'status' => InstructorStatus::Active->value,
            ])
            ->assertSessionHasErrors('status');

        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $this->instructor->id,
            'status' => InstructorStatus::PendingVerification->value,
        ]);
    }

    #[Test]
    public function instructors_index_prioritizes_pending_verification_newest_first_and_preserves_filters()
    {
        $olderPending = $this->instructor;
        $olderPending->forceFill(['created_at' => now()->subDays(5)])->save();

        $newerPending = User::factory()->create([
            'role' => 'instructor',
            'name' => 'Newer Pending Instructor',
            'email' => 'newer-pending@example.test',
        ]);
        $newerPending->assignRole('instructor');
        $newerPending->instructorProfile()->create([
            'status' => InstructorStatus::PendingVerification,
            'level' => 2,
            'rate_per_hour' => 800,
        ]);
        $newerPending->forceFill(['created_at' => now()->subDay()])->save();

        $newestActive = User::factory()->create([
            'role' => 'instructor',
            'name' => 'Newest Active Instructor',
            'email' => 'newest-active@example.test',
        ]);
        $newestActive->assignRole('instructor');
        $newestActive->instructorProfile()->create([
            'status' => InstructorStatus::Active,
            'level' => 2,
            'rate_per_hour' => 900,
        ]);
        $newestActive->forceFill(['created_at' => now()])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.instructors.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('instructors.data.0.id', $newerPending->id)
                ->where('instructors.data.1.id', $olderPending->id)
                ->where('instructors.data.2.id', $newestActive->id)
            );

        // Verify filters still operate properly with the instructor_profiles join.
        $this->actingAs($this->admin)
            ->get(route('admin.instructors.index', [
                'status' => InstructorStatus::Active->value,
                'level' => 2,
                'search' => 'Newest Active',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('instructors.data', 1)
                ->where('instructors.data.0.id', $newestActive->id)
            );
    }

    #[Test]
    public function admin_can_save_and_validate_instructor_birthday(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.instructors.store'), [
                'name' => 'Underage Applicant',
                'email' => 'underage@example.com',
                'password' => 'password123',
                'birthday' => now()->subYears(17)->toDateString(),
                'level' => 1,
                'rate_per_hour' => 600,
            ])
            ->assertSessionHasErrors('birthday');

        $this->actingAs($this->admin)
            ->post(route('admin.instructors.store'), [
                'name' => 'Adult Instructor',
                'email' => 'adult@example.com',
                'password' => 'password123',
                'birthday' => '1995-11-03',
                'level' => 1,
                'rate_per_hour' => 600,
            ])
            ->assertRedirect();

        $adult = User::where('email', 'adult@example.com')->firstOrFail();
        $this->assertSame('1995-11-03', $adult->instructorProfile?->birthday?->format('Y-m-d'));

        $this->actingAs($this->admin)
            ->put(route('admin.instructors.update', $adult->id), [
                'name' => $adult->name,
                'email' => $adult->email,
                'birthday' => '1996-02-14',
                'level' => 1,
                'rate_per_hour' => 600,
                'status' => InstructorStatus::PendingVerification->value,
            ])
            ->assertRedirect(route('admin.instructors.show', $adult->id));

        $this->assertSame('1996-02-14', $adult->instructorProfile()->firstOrFail()->birthday?->format('Y-m-d'));
    }
}
