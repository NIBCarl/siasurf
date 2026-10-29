<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
    }

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_user_model_enforces_the_verified_middleware(): void
    {
        // EnsureEmailIsVerified only does anything when the model implements
        // the contract. Without this the `verified` routes were a no-op.
        $this->assertInstanceOf(
            \Illuminate\Contracts\Auth\MustVerifyEmail::class,
            User::factory()->make()
        );
    }

    public function test_an_unverified_student_is_sent_to_the_verification_notice(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('student', 'web');

        $user = User::factory()->unverified()->create(['role' => 'student']);
        $user->assignRole('student');
        $user->studentProfile()->create([
            'skill_level' => 'beginner',
            'is_first_time' => true,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/student/dashboard')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_a_verified_student_can_reach_the_dashboard(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('student', 'web');

        $user = User::factory()->create(['role' => 'student']);
        $user->assignRole('student');
        $user->studentProfile()->create([
            'skill_level' => 'beginner',
            'is_first_time' => true,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/student/dashboard')
            ->assertOk();
    }
}
