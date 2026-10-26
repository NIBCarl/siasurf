<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression tests for SQLSTATE[22003] on `instructor_profiles.rate_per_hour`.
 *
 * The column is `decimal(10,2)`, so anything above 99,999,999.99 used to reach
 * MySQL and surface as a 500 instead of a validation error, because the only
 * rule was `min:600`.
 */
class RatePerHourValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('admin');
    }

    private function onboardingPayload(float $rate): array
    {
        return [
            'bio' => 'Certified Siargao surf coach.',
            'level' => 1,
            'rate_per_hour' => $rate,
            'certificates' => [
                UploadedFile::fake()->create('bls-cert.pdf', 120, 'application/pdf'),
            ],
        ];
    }

    public function test_the_configured_ceiling_is_1500_by_default(): void
    {
        $this->assertSame(1500.0, PricingService::maxRatePerHour());
        $this->assertSame(600.0, PricingService::minRatePerHour());
    }

    public function test_onboarding_rejects_a_rate_that_would_overflow_the_column(): void
    {
        // The exact value from the reported crash.
        $this->actingAs($this->instructor)
            ->post(route('instructor.onboarding.store'), $this->onboardingPayload(10000000000))
            ->assertSessionHasErrors('rate_per_hour');

        $this->assertDatabaseCount('instructor_profiles', 0);
    }

    public function test_onboarding_error_message_states_the_cap(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.onboarding.store'), $this->onboardingPayload(5000))
            ->assertSessionHasErrors([
                'rate_per_hour' => 'The standard rate cannot exceed ₱1,500 per hour.',
            ]);
    }

    public function test_onboarding_accepts_the_cap_exactly(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.onboarding.store'), $this->onboardingPayload(1500))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $this->instructor->id,
            'rate_per_hour' => 1500,
        ]);
    }

    public function test_onboarding_accepts_the_floor_exactly(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.onboarding.store'), $this->onboardingPayload(600))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $this->instructor->id,
            'rate_per_hour' => 600,
        ]);
    }

    public function test_onboarding_rejects_a_rate_below_the_floor(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.onboarding.store'), $this->onboardingPayload(100))
            ->assertSessionHasErrors('rate_per_hour');
    }

    public function test_admin_cannot_register_an_instructor_above_the_cap(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.instructors.store'), [
                'name' => 'Overpriced Instructor',
                'email' => 'overpriced@example.com',
                'password' => 'password123',
                'level' => 3,
                'rate_per_hour' => 10000000000,
            ])
            ->assertSessionHasErrors('rate_per_hour');

        $this->assertDatabaseMissing('users', ['email' => 'overpriced@example.com']);
    }

    public function test_admin_cannot_edit_an_instructor_above_the_cap(): void
    {
        $profile = $this->instructor->instructorProfile()->create([
            'level' => 2,
            'status' => 'active',
            'rate_per_hour' => 800,
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.instructors.update', $this->instructor->id), [
                'name' => $this->instructor->name,
                'email' => $this->instructor->email,
                'level' => 2,
                'status' => 'active',
                'rate_per_hour' => 9000,
            ])
            ->assertSessionHasErrors('rate_per_hour');

        $this->assertSame('800.00', $profile->fresh()->rate_per_hour);
    }

    public function test_admin_can_save_a_rate_inside_the_band(): void
    {
        $this->instructor->instructorProfile()->create([
            'level' => 2,
            'status' => 'active',
            'rate_per_hour' => 800,
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.instructors.update', $this->instructor->id), [
                'name' => $this->instructor->name,
                'email' => $this->instructor->email,
                'level' => 2,
                'status' => 'active',
                'rate_per_hour' => 1450,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $this->instructor->id,
            'rate_per_hour' => 1450,
        ]);
    }

    public function test_pricing_service_rejects_a_rate_above_the_cap(): void
    {
        $service = app(PricingService::class);

        $this->assertFalse($service->isRateValid(10000000000, 1));
        $this->assertFalse($service->isRateValid(1500.01, 3));
        $this->assertTrue($service->isRateValid(1500, 3));
        $this->assertTrue($service->isRateValid(800, 2));
    }

    /**
     * The rate dialogs read their bounds from shared Inertia props, so those
     * props are part of the contract: if they disappear the UI silently falls
     * back to hardcoded numbers.
     */
    public function test_pricing_bounds_are_shared_with_every_page(): void
    {
        $this->get(route('surf-spots.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pricing.minRatePerHour', 600)
                ->where('pricing.maxRatePerHour', 1500)
                ->where('pricing.rangeLabel', '₱600–₱1,500')
            );
    }
}
