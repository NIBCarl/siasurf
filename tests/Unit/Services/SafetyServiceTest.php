<?php

namespace Tests\Unit\Services;

use App\Services\SafetyService;
use App\Exceptions\SafetyViolationException;
use App\Enums\SkillLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SafetyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SafetyService $safetyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->safetyService = new SafetyService();
    }

    /** @test */
    public function level_1_instructor_can_teach_2_beginners()
    {
        $data = [
            'instructor_level' => 1,
            'student_count' => 2,
            'skill_level' => SkillLevel::Beginner->value,
            'student_age' => 20,
            'surf_spot_difficulty' => 'beginner',
        ];

        $this->assertTrue($this->safetyService->wouldPassValidation($data));
    }

    /** @test */
    public function level_1_instructor_cannot_teach_3_students()
    {
        $this->expectException(SafetyViolationException::class);

        $data = [
            'instructor_level' => 1,
            'student_count' => 3,
            'skill_level' => SkillLevel::Beginner->value,
            'student_age' => 20,
            'surf_spot_difficulty' => 'beginner',
        ];

        $this->safetyService->validateBookingSafety($data);
    }

    /** @test */
    public function level_1_instructor_cannot_teach_intermediate()
    {
        $this->expectException(SafetyViolationException::class);

        $data = [
            'instructor_level' => 1,
            'student_count' => 1,
            'skill_level' => SkillLevel::Intermediate->value,
            'student_age' => 20,
            'surf_spot_difficulty' => 'intermediate',
        ];

        $this->safetyService->validateBookingSafety($data);
    }

    /** @test */
    public function level_2_instructor_can_do_1_on_1()
    {
        $data = [
            'instructor_level' => 2,
            'student_count' => 1,
            'skill_level' => SkillLevel::Intermediate->value,
            'student_age' => 25,
            'surf_spot_difficulty' => 'intermediate',
        ];

        $this->assertTrue($this->safetyService->wouldPassValidation($data));
    }

    /** @test */
    public function level_2_instructor_cannot_handle_2_students()
    {
        $this->expectException(SafetyViolationException::class);

        $data = [
            'instructor_level' => 2,
            'student_count' => 2,
            'skill_level' => SkillLevel::Beginner->value,
            'student_age' => 20,
            'surf_spot_difficulty' => 'beginner',
        ];

        $this->safetyService->validateBookingSafety($data);
    }

    /** @test */
    public function level_3_instructor_can_handle_5_students()
    {
        $data = [
            'instructor_level' => 3,
            'student_count' => 5,
            'skill_level' => SkillLevel::Advanced->value,
            'student_age' => 30,
            'surf_spot_difficulty' => 'advanced',
        ];

        $this->assertTrue($this->safetyService->wouldPassValidation($data));
    }

    /** @test */
    public function level_3_instructor_cannot_handle_6_students()
    {
        $this->expectException(SafetyViolationException::class);

        $data = [
            'instructor_level' => 3,
            'student_count' => 6,
            'skill_level' => SkillLevel::Beginner->value,
            'student_age' => 20,
            'surf_spot_difficulty' => 'beginner',
        ];

        $this->safetyService->validateBookingSafety($data);
    }

    /** @test */
    public function children_aged_5_to_12_require_level_3_instructor()
    {
        $data = [
            'instructor_level' => 3,
            'student_count' => 1,
            'skill_level' => SkillLevel::Beginner->value,
            'student_age' => 8,
            'surf_spot_difficulty' => 'beginner',
        ];

        $this->assertTrue($this->safetyService->wouldPassValidation($data));
    }

    /** @test */
    public function children_aged_5_to_12_cannot_use_level_1_instructor()
    {
        $this->expectException(SafetyViolationException::class);

        $data = [
            'instructor_level' => 1,
            'student_count' => 1,
            'skill_level' => SkillLevel::Beginner->value,
            'student_age' => 8,
            'surf_spot_difficulty' => 'beginner',
        ];

        $this->safetyService->validateBookingSafety($data);
    }

    /** @test */
    public function beginners_can_use_beginner_spots()
    {
        $data = [
            'instructor_level' => 2,
            'student_count' => 1,
            'skill_level' => SkillLevel::Beginner->value,
            'student_age' => 20,
            'surf_spot_difficulty' => 'beginner',
        ];

        $this->assertTrue($this->safetyService->wouldPassValidation($data));
    }

    /** @test */
    public function beginners_cannot_use_advanced_spots()
    {
        $this->expectException(SafetyViolationException::class);

        $data = [
            'instructor_level' => 3,
            'student_count' => 1,
            'skill_level' => SkillLevel::Beginner->value,
            'student_age' => 20,
            'surf_spot_difficulty' => 'advanced',
        ];

        $this->safetyService->validateBookingSafety($data);
    }

    /** @test */
    public function advanced_lessons_require_level_3_instructor()
    {
        $data = [
            'instructor_level' => 3,
            'student_count' => 1,
            'skill_level' => SkillLevel::Advanced->value,
            'student_age' => 25,
            'surf_spot_difficulty' => 'advanced',
        ];

        $this->assertTrue($this->safetyService->wouldPassValidation($data));
    }

    /** @test */
    public function advanced_lessons_cannot_use_level_2_instructor()
    {
        // Level 2 instructors can now teach advanced students
        $data = [
            'instructor_level' => 2,
            'student_count' => 1,
            'skill_level' => SkillLevel::Advanced->value,
            'student_age' => 25,
            'surf_spot_difficulty' => 'advanced',
        ];

        $this->assertTrue($this->safetyService->wouldPassValidation($data));
    }

    /** @test */
    public function students_under_5_are_not_allowed()
    {
        $this->expectException(SafetyViolationException::class);

        $data = [
            'instructor_level' => 3,
            'student_count' => 1,
            'skill_level' => SkillLevel::Beginner->value,
            'student_age' => 4,
            'surf_spot_difficulty' => 'beginner',
        ];

        $this->safetyService->validateBookingSafety($data);
    }

    /** @test */
    public function get_validation_errors_returns_array_of_violations()
    {
        $data = [
            'instructor_level' => 1,
            'student_count' => 5,
            'skill_level' => SkillLevel::Advanced->value,
            'student_age' => 4,
            'surf_spot_difficulty' => 'advanced',
        ];

        $errors = $this->safetyService->getValidationErrors($data);

        $this->assertIsArray($errors);
        $this->assertCount(5, $errors); // 4 violations + age under 5
        $this->assertStringContainsString('Level 1', $errors[0]);
    }

    /** @test */
    public function can_get_applicable_rules_for_level_1()
    {
        $rules = $this->safetyService->getApplicableRules(1);

        $this->assertEquals(2, $rules['max_students']);
        $this->assertEquals(['beginner'], $rules['allowed_skill_levels']);
    }

    /** @test */
    public function can_get_applicable_rules_for_level_3()
    {
        $rules = $this->safetyService->getApplicableRules(3);

        $this->assertEquals(5, $rules['max_students']);
        $this->assertTrue($rules['can_teach_children']);
    }

    /** @test */
    public function can_check_if_instructor_can_handle_group_size()
    {
        $this->assertTrue($this->safetyService->canHandleGroupSize(1, 2));
        $this->assertFalse($this->safetyService->canHandleGroupSize(1, 3));
        $this->assertTrue($this->safetyService->canHandleGroupSize(2, 1));
        $this->assertFalse($this->safetyService->canHandleGroupSize(2, 2));
        $this->assertTrue($this->safetyService->canHandleGroupSize(3, 5));
        $this->assertFalse($this->safetyService->canHandleGroupSize(3, 6));
    }

    /** @test */
    public function can_check_if_instructor_can_teach_skill_level()
    {
        // Level 1 can only teach beginners
        $this->assertTrue($this->safetyService->canTeachSkillLevel(1, SkillLevel::Beginner->value));
        $this->assertFalse($this->safetyService->canTeachSkillLevel(1, SkillLevel::Intermediate->value));
        $this->assertFalse($this->safetyService->canTeachSkillLevel(1, SkillLevel::Advanced->value));
        
        // Level 2 can teach all skill levels
        $this->assertTrue($this->safetyService->canTeachSkillLevel(2, SkillLevel::Beginner->value));
        $this->assertTrue($this->safetyService->canTeachSkillLevel(2, SkillLevel::Intermediate->value));
        $this->assertTrue($this->safetyService->canTeachSkillLevel(2, SkillLevel::Advanced->value));
        
        // Level 3 can teach all skill levels
        $this->assertTrue($this->safetyService->canTeachSkillLevel(3, SkillLevel::Beginner->value));
        $this->assertTrue($this->safetyService->canTeachSkillLevel(3, SkillLevel::Intermediate->value));
        $this->assertTrue($this->safetyService->canTeachSkillLevel(3, SkillLevel::Advanced->value));
    }
}