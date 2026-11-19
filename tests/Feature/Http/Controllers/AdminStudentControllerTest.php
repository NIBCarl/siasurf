<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminStudentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('student', 'web');
    }

    public function test_admin_student_directory_lists_newest_students_first(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole('admin');

        $oldest = User::factory()->create([
            'role' => 'student',
            'name' => 'Oldest Student',
            'created_at' => now()->subDays(2),
        ]);
        $oldest->assignRole('student');

        $middle = User::factory()->create([
            'role' => 'student',
            'name' => 'Middle Student',
            'created_at' => now()->subDay(),
        ]);
        $middle->assignRole('student');

        $newest = User::factory()->create([
            'role' => 'student',
            'name' => 'Newest Student',
            'created_at' => now(),
        ]);
        $newest->assignRole('student');

        $this->actingAs($admin)
            ->get(route('admin.students.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Students/Index')
                ->where('students.data.0.id', $newest->id)
                ->where('students.data.1.id', $middle->id)
                ->where('students.data.2.id', $oldest->id)
            );
    }
}
