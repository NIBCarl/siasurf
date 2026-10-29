<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\InstructorLevel;
use App\Models\Certificate;
use App\Models\SurfSpot;
use App\Models\User;
use App\Services\QRCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 1000,
        ]);

        Certificate::factory()->create([
            'user_id' => $this->instructor->id,
            'type' => CertificateType::BLS->value,
            'status' => CertificateStatus::Verified->value,
            'verified_at' => now()->subMonth(),
            'expires_at' => now()->addYear(),
        ]);

        SurfSpot::factory()->create(['difficulty' => 'beginner']);
    }

    public function test_verified_instructor_qr_page_shows_a_live_verified_status(): void
    {
        $this->get(route('walkin.scan', $this->instructor))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/WalkIn/Scan')
                ->where('verification.status', 'verified')
                ->where('verification.can_book', true)
                ->where('instructor.id', $this->instructor->id)
            );
    }

    public function test_suspended_instructor_qr_page_disables_booking(): void
    {
        $this->instructor->instructorProfile->update([
            'status' => 'suspended',
            'suspended_until' => now()->addWeeks(2),
            'suspension_reason' => 'Three active strikes.',
        ]);

        $this->get(route('walkin.scan', $this->instructor))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/WalkIn/Scan')
                ->where('verification.status', 'suspended')
                ->where('verification.can_book', false)
            );
    }

    public function test_pending_instructor_qr_page_is_not_verified(): void
    {
        $this->instructor->instructorProfile->update(['status' => 'pending_verification']);

        $this->get(route('walkin.scan', $this->instructor))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('verification.status', 'pending')
                ->where('verification.can_book', false)
            );
    }

    public function test_instructor_with_only_an_expired_certificate_is_not_verified(): void
    {
        $this->instructor->certificates()->delete();

        Certificate::factory()->create([
            'user_id' => $this->instructor->id,
            'type' => CertificateType::BLS->value,
            'status' => CertificateStatus::Verified->value,
            'verified_at' => now()->subYear(),
            'expires_at' => now()->subDay(),
        ]);

        $this->get(route('walkin.scan', $this->instructor))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('verification.status', 'unverified')
                ->where('verification.can_book', false)
            );
    }

    public function test_public_profile_returns_404_for_a_suspended_instructor(): void
    {
        $this->instructor->instructorProfile->update([
            'status' => 'suspended',
            'suspended_until' => now()->addMonth(),
        ]);

        $this->get(route('instructors.profile', $this->instructor))
            ->assertNotFound();
    }

    public function test_public_profile_is_available_for_a_verified_instructor(): void
    {
        $this->get(route('instructors.profile', $this->instructor))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Instructors/Profile')
                ->where('instructor.id', $this->instructor->id)
            );
    }

    public function test_qr_image_route_serves_the_stored_svg(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('instructors/' . $this->instructor->id . '/qr-code.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $this->instructor->instructorProfile->update([
            'qr_code_path' => 'instructors/' . $this->instructor->id . '/qr-code.svg',
        ]);

        $this->get(route('qr.image', $this->instructor))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_instructor_qr_page_is_available_to_the_owner(): void
    {
        Storage::fake('local');

        $this->actingAs($this->instructor)
            ->get(route('instructor.qr.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Instructor/Qr/Show')
                ->where('instructor.id', $this->instructor->id)
                ->where('verification.status', 'verified')
            );
    }

    public function test_qr_service_generates_a_static_svg_for_the_live_page(): void
    {
        Storage::fake('local');

        $path = app(QRCodeService::class)->generateInstructorQR($this->instructor->instructorProfile);

        $this->assertSame(
            'instructors/' . $this->instructor->id . '/qr-code.svg',
            $path
        );
        Storage::disk('local')->assertExists($path);
        $this->assertSame($path, $this->instructor->instructorProfile->fresh()->qr_code_path);
    }


    public function test_search_lists_a_verified_instructor(): void
    {
        $this->get(route('instructors.search'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('instructors.data', 1)
                ->where('instructors.data.0.id', $this->instructor->id)
            );
    }

    public function test_search_hides_an_instructor_with_an_expired_certificate(): void
    {
        $this->instructor->certificates()->delete();

        Certificate::factory()->create([
            'user_id' => $this->instructor->id,
            'type' => CertificateType::BLS->value,
            'status' => CertificateStatus::Verified->value,
            'verified_at' => now()->subYear(),
            'expires_at' => now()->subDay(),
        ]);

        $this->get(route('instructors.search'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('instructors.data', 0));
    }

}
