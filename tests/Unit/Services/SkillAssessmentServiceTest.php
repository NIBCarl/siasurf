<?php

namespace Tests\Unit\Services;

use App\Services\SkillAssessmentService;
use Tests\TestCase;

class SkillAssessmentServiceTest extends TestCase
{
    private SkillAssessmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SkillAssessmentService;
    }

    public function test_public_definition_strips_correct_answer_indices(): void
    {
        $definition = $this->service->publicDefinition();

        $this->assertCount(4, $definition['questions']);

        foreach ($definition['questions'] as $question) {
            $this->assertArrayNotHasKey('correct_index', $question);
            $this->assertArrayHasKey('options', $question);
        }
    }

    public function test_intermediate_requires_all_basic_four_intermediate_and_three_correct_with_water_safety(): void
    {
        $result = $this->service->evaluate([
            'can_swim_deep_water' => true,
            'is_first_time' => false,
            'skills' => [
                'basic' => [0, 1],
                'intermediate' => [0, 1, 2, 3],
                'advanced' => [],
            ],
            'answers' => [0 => 1, 1 => 1, 2 => 0, 3 => 0], // 3/4, Q1 right
        ]);

        $this->assertSame('intermediate', $result['checklist_level']);
        $this->assertSame('intermediate', $result['knowledge_level']);
        $this->assertSame('intermediate', $result['assessed_level']);
        $this->assertSame(3, $result['knowledge_score']);
        $this->assertTrue($result['water_safety_passed']);
        $this->assertSame([], $result['safety_flags']);
    }

    public function test_advanced_requires_four_advanced_skills_and_all_four_correct_answers(): void
    {
        $result = $this->service->evaluate([
            'can_swim_deep_water' => true,
            'is_first_time' => false,
            'skills' => [
                'basic' => [0, 1],
                'intermediate' => [0, 1, 2, 3],
                'advanced' => [0, 1, 2, 3],
            ],
            'answers' => [0 => 1, 1 => 1, 2 => 0, 3 => 1], // 4/4
        ]);

        $this->assertSame('advanced', $result['checklist_level']);
        $this->assertSame('advanced', $result['knowledge_level']);
        $this->assertSame('advanced', $result['assessed_level']);
        $this->assertSame(4, $result['knowledge_score']);
        $this->assertSame([], $result['safety_flags']);
    }

    public function test_final_level_is_the_lower_of_checklist_and_knowledge(): void
    {
        $result = $this->service->evaluate([
            'can_swim_deep_water' => true,
            'is_first_time' => false,
            'skills' => [
                'basic' => [0, 1],
                'intermediate' => [0, 1, 2, 3, 4],
                'advanced' => [0, 1, 2, 3, 4],
            ],
            'answers' => [0 => 1, 1 => 1, 2 => 0, 3 => 2], // 3/4 -> intermediate knowledge
        ]);

        $this->assertSame('advanced', $result['checklist_level']);
        $this->assertSame('intermediate', $result['knowledge_level']);
        $this->assertSame('intermediate', $result['assessed_level']);
    }

    public function test_first_time_flag_caps_level_at_beginner(): void
    {
        $result = $this->service->evaluate([
            'can_swim_deep_water' => true,
            'is_first_time' => true,
            'skills' => [
                'basic' => [0, 1],
                'intermediate' => [0, 1, 2, 3],
                'advanced' => [0, 1, 2, 3],
            ],
            'answers' => [0 => 1, 1 => 1, 2 => 0, 3 => 1],
        ]);

        $this->assertSame('beginner', $result['assessed_level']);
        $this->assertCount(1, $result['safety_flags']);
        $this->assertSame('first_time_or_unsure', $result['safety_flags'][0]['code']);
    }

    public function test_unconfirmed_deep_water_swimming_caps_at_beginner_with_warning(): void
    {
        $result = $this->service->evaluate([
            'can_swim_deep_water' => false,
            'is_first_time' => false,
            'skills' => [
                'basic' => [0, 1],
                'intermediate' => [0, 1, 2, 3],
                'advanced' => [0, 1, 2, 3],
            ],
            'answers' => [0 => 1, 1 => 1, 2 => 0, 3 => 1],
        ]);

        $this->assertSame('beginner', $result['assessed_level']);
        $this->assertCount(1, $result['safety_flags']);
        $this->assertSame('deep_water_swimming_unconfirmed', $result['safety_flags'][0]['code']);
        $this->assertSame('warning', $result['safety_flags'][0]['severity']);
    }

    public function test_wrong_water_safety_answer_caps_at_beginner_with_warning(): void
    {
        $result = $this->service->evaluate([
            'can_swim_deep_water' => true,
            'is_first_time' => false,
            'skills' => [
                'basic' => [0, 1],
                'intermediate' => [0, 1, 2, 3],
                'advanced' => [0, 1, 2, 3],
            ],
            'answers' => [0 => 0, 1 => 1, 2 => 0, 3 => 1], // 3/4, but Q1 wrong
        ]);

        $this->assertFalse($result['water_safety_passed']);
        $this->assertSame('beginner', $result['knowledge_level']);
        $this->assertSame('beginner', $result['assessed_level']);
        $this->assertSame('water_safety_incorrect', $result['safety_flags'][0]['code']);
        $this->assertSame('warning', $result['safety_flags'][0]['severity']);
    }
}
