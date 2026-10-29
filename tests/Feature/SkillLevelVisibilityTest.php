<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\InstructorLevel;
use App\Models\Certificate;
use App\Models\SurfSpot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * FR-2.x skill-level matching: the directory only shows instructors whose
 * certification covers the student's own level.
 */
class SkillLevelVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, User> level => instructor */
    private array $instructors = [];

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        SurfSpot::factory()->create(['difficulty' => 'beginner']);

        foreach ([InstructorLevel::Level1, InstructorLevel::Level2, InstructorLevel::Level3] as $level) {
            $instructor = User::factory()->create(['role' => 'instructor']);
            $instructor->assignRole('instructor');
            $instructor->instructorProfile()->create([
                'level' => $level,
                'status' => 'active',
                'rate_per_hour' => 1000,
            ]);

            Certificate::factory()->create([
                'user_id' => $instructor->id,
                'type' => CertificateType::SISA->value,
                'status' => CertificateStatus::Verified->value,
                'verified_at' => now()->subMonth(),
                'expires_at' => now()->addYear(),
            ]);

            $this->instructors[$level->value] = $instructor;
        }
    }

    private function studentWithLevel(string $level): User
    {
        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');
        $student->studentProfile()->create([
            'skill_level' => $level,
            'is_first_time' => false,
            'status' => 'active',
        ]);

        return $student;
    }

    private function visibleInstructorIds(User $student, array $query = []): array
    {
        $response = $this->actingAs($student)->get(route('instructors.search', $query));
        $response->assertOk();

        $ids = [];

        $response->assertInertia(function (AssertableJson $page) use (&$ids) {
            $ids = collect($page->toArray()['props']['instructors']['data'])
                ->pluck('id')
                ->all();
        });

        return $ids;
    }

    /** @test */
    public function a_beginner_student_sees_levels_one_two_and_three(): void
    {
        $ids = $this->visibleInstructorIds($this->studentWithLevel('beginner'));

        $this->assertEqualsCanonicalizing(
            [$this->instructors[1]->id, $this->instructors[2]->id, $this->instructors[3]->id],
            $ids,
        );
    }

    /** @test */
    public function an_intermediate_student_cannot_see_level_one(): void
    {
        $ids = $this->visibleInstructorIds($this->studentWithLevel('intermediate'));

        $this->assertEqualsCanonicalizing(
            [$this->instructors[2]->id, $this->instructors[3]->id],
            $ids,
        );
    }

    /** @test */
    public function an_advanced_student_only_sees_level_three(): void
    {
        $ids = $this->visibleInstructorIds($this->studentWithLevel('advanced'));

        $this->assertSame([$this->instructors[3]->id], $ids);
    }

    /** @test */
    public function an_advanced_student_cannot_widen_the_results_with_a_level_filter(): void
    {
        $ids = $this->visibleInstructorIds($this->studentWithLevel('advanced'), ['level' => 1]);

        $this->assertSame([], $ids);
    }

    /** @test */
    public function an_advanced_student_cannot_open_a_level_one_profile(): void
    {
        $student = $this->studentWithLevel('advanced');

        $this->actingAs($student)
            ->get(route('instructors.profile', $this->instructors[1]))
            ->assertNotFound();

        $this->actingAs($student)
            ->get(route('instructors.profile', $this->instructors[3]))
            ->assertOk();
    }

    /** @test */
    public function an_intermediate_student_cannot_open_a_level_one_booking_form(): void
    {
        $intermediate = $this->studentWithLevel('intermediate');

        $this->actingAs($intermediate)
            ->get(route('bookings.create', $this->instructors[1]))
            ->assertNotFound();
    }
}
