<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');
    }

    public function test_guests_can_view_privacy_policy_and_terms_of_service(): void
    {
        $this->get('/privacy-policy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Legal/PrivacyPolicy')
                ->has('legal.effective_date')
                ->has('legal.last_updated')
                ->has('legal.organization')
                ->has('legal.dpo_name')
                ->has('legal.contact_email')
                ->has('legal.contact_phone')
                ->has('legal.office_address'));

        $this->get('/terms-of-service')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Legal/TermsOfService')
                ->has('legal.effective_date')
                ->where('rules.service_fee_percent', 7)
                ->where('rules.teaching_min', 600)
                ->where('rules.teaching_max', 1000)
                ->where('rules.strike_fine_amount', 500));
    }

    public function test_authenticated_users_can_view_legal_pages(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');

        $this->actingAs($student)->get('/privacy-policy')->assertOk();
        $this->actingAs($student)->get('/terms-of-service')->assertOk();
    }

    public function test_short_legal_aliases_redirect_permanently(): void
    {
        $this->get('/privacy')->assertRedirect('/privacy-policy')->assertStatus(301);
        $this->get('/terms')->assertRedirect('/terms-of-service')->assertStatus(301);
    }
}
