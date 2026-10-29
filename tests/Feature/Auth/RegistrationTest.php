<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed Spatie roles
        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'student',
            'skill_level' => 'beginner',
            'is_first_time' => true,
        ], $overrides);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', $this->payload());

        $this->assertAuthenticated();
        // New accounts land on the verification screen, not the dashboard,
        // because every protected route now requires a verified email.
        $response->assertRedirect(route('verification.notice'));
    }

    public function test_a_verification_email_is_sent_when_a_user_registers(): void
    {
        Notification::fake();

        $this->post('/register', $this->payload())
            ->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'test@example.com')->firstOrFail();

        // The framework's Registered listener must hand this to the VerifyEmail
        // notification. If Mail is misconfigured this still proves the wiring.
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertFalse($user->hasVerifiedEmail());
    }

    public function test_an_unverified_user_can_request_a_new_verification_link(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create(['role' => 'student']);
        $user->assignRole('student');

        $this->actingAs($user)
            ->post('/email/verification-notification')
            ->assertRedirect()
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}

