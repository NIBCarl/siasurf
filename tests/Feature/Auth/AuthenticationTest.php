<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed Spatie roles so registration's assignRole() works
        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_users_can_register_as_student(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test Student',
            'email' => 'student@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'student',
            'phone' => '+63 912 345 6789',
        ]);

        $this->assertAuthenticated();
        
        $this->assertDatabaseHas('users', [
            'email' => 'student@example.com',
            'role' => 'student',
        ]);
    }

    public function test_users_can_register_as_instructor(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test Instructor',
            'email' => 'instructor@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'instructor',
            'phone' => '+63 912 345 6789',
        ]);

        $this->assertAuthenticated();
        
        $this->assertDatabaseHas('users', [
            'email' => 'instructor@example.com',
            'role' => 'instructor',
        ]);

        // Check instructor profile was created
        $user = User::where('email', 'instructor@example.com')->first();
        $this->assertNotNull($user->instructorProfile);
    }

    public function test_registration_requires_valid_role(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'invalid_role',
        ]);

        $response->assertSessionHasErrors(['role']);
    }

    public function test_students_cannot_access_instructor_routes(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $response = $this->actingAs($student)
            ->get('/instructor/bookings');

        $response->assertForbidden();
    }

    public function test_instructors_cannot_access_admin_routes(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor']);

        $response = $this->actingAs($instructor)
            ->get('/admin/dashboard');

        $response->assertForbidden();
    }
}
