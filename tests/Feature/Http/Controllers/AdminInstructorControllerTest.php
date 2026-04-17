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
use Tests\TestCase;

class AdminInstructorControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();
        
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

    /** @test */
    public function admin_can_view_instructors_list()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.instructors.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Instructors/Index')
            );
    }

    /** @test */
    public function non_admin_cannot_view_instructors_list()
    {
        $response = $this->actingAs($this->instructor)
            ->get(route('admin.instructors.index'));

        $response->assertForbidden();
    }

    /** @test */
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

    /** @test */
    public function admin_can_verify_instructor()
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.instructors.verify', $this->instructor->id));

        $response->assertRedirect();
        
        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $this->instructor->id,
            'status' => InstructorStatus::Active->value,
        ]);
    }

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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
}
