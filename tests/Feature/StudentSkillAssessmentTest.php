<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentSkillAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');

        $this->student = User::factory()->create(['role' => 'student']);
        $this->student->assignRole('student');
        $this->student->studentProfile()->create([
            'skill_level' => 'beginner',
            'is_first_time' => true,
        ]);
    }

    public function test_student_registration_always_defaults_to_unassessed_beginner(): void
    {
        $this->post('/register', [
            'name' => 'New Surfer',
            'email' => 'newsurfer@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'student',
            'skill_level' => 'advanced',
            'is_first_time' => false,
            'terms_accepted' => true,
        ])->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'newsurfer@example.test')->firstOrFail();

        $this->assertSame('beginner', $user->studentProfile->skill_level);
        $this->assertTrue($user->studentProfile->is_first_time);
        $this->assertNull($user->studentProfile->skill_assessed_at);
    }

    public function test_onboarding_page_exposes_assessment_definition_without_answer_keys(): void
    {
        $response = $this->actingAs($this->student)
            ->get(route('student.onboarding'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/Onboarding')
                ->where('latestAssessment', null)
                ->has('assessmentDefinition.questions', 4)
            );

        $questions = $response->viewData('page')['props']['assessmentDefinition']['questions'];
        foreach ($questions as $question) {
            $this->assertArrayNotHasKey('correct_index', $question);
        }
    }

    public function test_assessment_requires_date_of_birth_water_safety_answer_and_confirmation(): void
    {
        $this->actingAs($this->student)
            ->from(route('student.onboarding'))
            ->post(route('student.onboarding.assessment'), [
                'can_swim_deep_water' => true,
                'is_first_time' => false,
                'skills' => ['basic' => [0, 1], 'intermediate' => [], 'advanced' => []],
                'answers' => [0 => null, 1 => 1, 2 => 0, 3 => 1],
                'confirmed' => false,
            ])
            ->assertSessionHasErrors(['date_of_birth', 'answers.0', 'confirmed']);
    }

    public function test_assessment_rejects_age_under_five_and_flags_minors_under_eighteen(): void
    {
        // Under 5 must be rejected cleanly.
        $this->actingAs($this->student)
            ->from(route('student.onboarding'))
            ->post(route('student.onboarding.assessment'), [
                'date_of_birth' => now()->subYears(4)->format('Y-m-d'),
                'can_swim_deep_water' => true,
                'is_first_time' => true,
                'skills' => ['basic' => [], 'intermediate' => [], 'advanced' => []],
                'answers' => [0 => 1, 1 => 1, 2 => 0, 3 => 1],
                'confirmed' => true,
            ])
            ->assertSessionHasErrors('date_of_birth');

        $this->assertDatabaseCount('student_skill_assessments', 0);

        // Age 15 (under 18) proceeds, saves birthday, and flashes parental consent notice.
        $minorDob = now()->subYears(15)->format('Y-m-d');

        $this->actingAs($this->student)
            ->post(route('student.onboarding.assessment'), [
                'date_of_birth' => $minorDob,
                'can_swim_deep_water' => true,
                'is_first_time' => false,
                'skills' => [
                    'basic' => [0, 1],
                    'intermediate' => [0, 1, 2, 3],
                    'advanced' => [],
                ],
                'answers' => [0 => 1, 1 => 1, 2 => 0, 3 => 1],
                'confirmed' => true,
            ])
            ->assertRedirect(route('student.onboarding'))
            ->assertSessionHas('success', function (string $msg) {
                return str_contains($msg, 'parent or guardian consent');
            });

        $profile = $this->student->studentProfile->fresh();
        $this->assertSame($minorDob, $profile->birthday->format('Y-m-d'));
        $this->assertSame('intermediate', $profile->skill_level);
    }

    public function test_submitting_assessment_sets_student_skill_level_and_locks_retakes(): void
    {
        $payload = [
            'date_of_birth' => now()->subYears(24)->format('Y-m-d'),
            'can_swim_deep_water' => true,
            'is_first_time' => false,
            'skills' => [
                'basic' => [0, 1],
                'intermediate' => [0, 1, 2, 3],
                'advanced' => [],
            ],
            'answers' => [0 => 1, 1 => 1, 2 => 0, 3 => 0],
            'confirmed' => true,
        ];

        $this->actingAs($this->student)
            ->post(route('student.onboarding.assessment'), $payload)
            ->assertRedirect(route('student.onboarding'));

        $profile = $this->student->studentProfile->fresh();
        $this->assertSame('intermediate', $profile->skill_level);
        $this->assertFalse($profile->is_first_time);
        $this->assertNotNull($profile->skill_assessed_at);
        $this->assertDatabaseCount('student_skill_assessments', 1);

        // Second attempt is blocked to prevent brute-forcing answers.
        $this->actingAs($this->student)
            ->from(route('student.onboarding'))
            ->post(route('student.onboarding.assessment'), [
                ...$payload,
                'skills' => [
                    'basic' => [0, 1],
                    'intermediate' => [0, 1, 2, 3, 4],
                    'advanced' => [0, 1, 2, 3, 4],
                ],
                'answers' => [0 => 1, 1 => 1, 2 => 0, 3 => 1],
            ])
            ->assertSessionHasErrors('assessment');

        $this->assertSame('intermediate', $this->student->studentProfile->fresh()->skill_level);
        $this->assertDatabaseCount('student_skill_assessments', 1);
    }

    public function test_safety_warnings_are_visible_to_instructor_and_admin(): void
    {
        // Student claims advanced skills & answers 4/4, but cannot swim in deep water.
        $this->actingAs($this->student)
            ->post(route('student.onboarding.assessment'), [
                'date_of_birth' => now()->subYears(22)->format('Y-m-d'),
                'can_swim_deep_water' => false,
                'is_first_time' => false,
                'skills' => [
                    'basic' => [0, 1],
                    'intermediate' => [0, 1, 2, 3],
                    'advanced' => [0, 1, 2, 3],
                ],
                'answers' => [0 => 1, 1 => 1, 2 => 0, 3 => 1],
                'confirmed' => true,
            ])
            ->assertRedirect(route('student.onboarding'));

        $this->assertSame('beginner', $this->student->studentProfile->fresh()->skill_level);

        $instructor = User::factory()->instructor()->create();
        $instructor->assignRole('instructor');
        $instructor->instructorProfile()->create([
            'bio' => 'Verified instructor',
            'level' => 1,
            'status' => 'active',
            'rate_per_hour' => 600,
        ]);
        $instructor->certificates()->create([
            'type' => 'sisa',
            'file_path' => 'certificates/sisa.pdf',
            'status' => 'verified',
        ]);

        $spot = SurfSpot::factory()->create(['difficulty' => 'beginner', 'is_active' => true]);

        $booking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $instructor->id,
            'surf_spot_id' => $spot->id,
            'status' => BookingStatus::Confirmed,
            'skill_level' => 'beginner',
        ]);

        $this->actingAs($instructor)
            ->get(route('instructor.bookings.show', $booking))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Instructor/Bookings/Show')
                ->where('studentAssessment.assessed_level', 'beginner')
                ->where('studentAssessment.has_safety_warnings', true)
                ->where('studentAssessment.knowledge_score', 4)
            );

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.students.show', $this->student))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Students/Show')
                ->where('skillAssessment.assessed_level', 'beginner')
                ->where('skillAssessment.has_safety_warnings', true)
                ->where('skillAssessment.ticked_skills_count', 10)
            );
    }
}
