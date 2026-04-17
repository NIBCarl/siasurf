<?php

namespace App\Services;

use App\Models\User;
use App\Models\SurfSpot;
use App\Models\SafetyValidationLog;
use App\Exceptions\SafetyViolationException;
use App\Enums\InstructorLevel;
use App\Enums\SkillLevel;

class SafetyService
{
    /**
     * Validate all safety rules for a booking
     * 
     * @throws SafetyViolationException
     */
    public function validateBookingSafety(array $data, ?int $userId = null): void
    {
        $violations = [];

        // Rule 1: Level 1 instructor - max 2 students (beginners only)
        $this->validateLevel1Rules($data, $violations);

        // Rule 2: Level 2 instructor - 1-on-1 only
        $this->validateLevel2Rules($data, $violations);

        // Rule 3: Level 3 instructor - max 5 students
        $this->validateLevel3Rules($data, $violations);

        // Rule 4: Age 5-12 requires 2 instructors
        $this->validateAgeRules($data, $violations);

        // Rule 5: Beginners restricted to safe zones
        $this->validateBeginnerZoneRules($data, $violations);

        // Rule 6: Certification must match lesson difficulty
        $this->validateCertificationMatchRules($data, $violations);

        // Log validation attempt
        $this->logValidation($data, $userId, empty($violations), $violations);

        // Throw exception if any violations found
        if (!empty($violations)) {
            throw new SafetyViolationException(
                'Safety requirements not met: ' . implode('; ', $violations)
            );
        }
    }

    /**
     * Rule 1: Level 1 instructor - max 2 students, beginners only
     */
    private function validateLevel1Rules(array $data, array &$violations): void
    {
        if (!isset($data['instructor_level']) || $data['instructor_level'] != 1) {
            return;
        }

        // Max 2 students for Level 1
        if (isset($data['student_count']) && $data['student_count'] > 2) {
            $violations[] = 'Level 1 instructors can handle maximum 2 students.';
        }

        // Beginners only
        if (isset($data['skill_level']) && $data['skill_level'] !== SkillLevel::Beginner->value) {
            $violations[] = 'Level 1 instructors can only teach beginners.';
        }
    }

    /**
     * Rule 2: Level 2 instructor - 1-on-1 sessions only
     */
    private function validateLevel2Rules(array $data, array &$violations): void
    {
        if (!isset($data['instructor_level']) || $data['instructor_level'] != 2) {
            return;
        }

        // 1-on-1 only
        if (isset($data['student_count']) && $data['student_count'] > 1) {
            $violations[] = 'Level 2 instructors can only do 1-on-1 sessions.';
        }
    }

    /**
     * Rule 3: Level 3 instructor - max 5 students
     */
    private function validateLevel3Rules(array $data, array &$violations): void
    {
        if (!isset($data['instructor_level']) || $data['instructor_level'] != 3) {
            return;
        }

        // Max 5 students
        if (isset($data['student_count']) && $data['student_count'] > 5) {
            $violations[] = 'Level 3 instructors can handle maximum 5 students.';
        }
    }

    /**
     * Rule 4: Children age 5-12 require 2 instructors
     */
    private function validateAgeRules(array $data, array &$violations): void
    {
        if (!isset($data['student_age'])) {
            return;
        }

        $age = $data['student_age'];

        // Age validation
        if ($age < 5) {
            $violations[] = 'Students must be at least 5 years old.';
            return;
        }

        // Age 5-12 requires 2 instructors
        if ($age >= 5 && $age <= 12) {
            // Check if we have instructor data
            if (isset($data['instructor_level'])) {
                // If single instructor, must be level 3
                if ($data['instructor_level'] !== 3) {
                    $violations[] = 'Children aged 5-12 require a Level 3 instructor OR 2 instructors (1 Level 3 + 1 Level 2+).';
                }
            }
        }
    }

    /**
     * Rule 5: Beginners restricted to safe zones only
     */
    private function validateBeginnerZoneRules(array $data, array &$violations): void
    {
        if (!isset($data['skill_level']) || $data['skill_level'] !== SkillLevel::Beginner->value) {
            return;
        }

        // Check surf spot difficulty
        if (isset($data['surf_spot_difficulty'])) {
            if ($data['surf_spot_difficulty'] !== 'beginner') {
                $violations[] = 'Beginners can only book beginner-friendly surf spots.';
            }
        }
    }

    /**
     * Rule 6: Certification must match lesson difficulty
     */
    private function validateCertificationMatchRules(array $data, array &$violations): void
    {
        if (!isset($data['skill_level']) || !isset($data['instructor_level'])) {
            return;
        }

        $skillLevel = $data['skill_level'];
        $instructorLevel = $data['instructor_level'];

        // Strict 1-to-1 matching: 
        // Level 1 -> Beginner
        // Level 2 -> Intermediate
        // Level 3 -> Advanced
        
        if ($instructorLevel === 1 && $skillLevel !== SkillLevel::Beginner->value) {
            $violations[] = 'Level 1 instructors are strictly paired with Beginner students.';
        } elseif ($instructorLevel === 2 && $skillLevel !== SkillLevel::Intermediate->value) {
            $violations[] = 'Level 2 instructors are strictly paired with Intermediate students.';
        } elseif ($instructorLevel === 3 && $skillLevel !== SkillLevel::Advanced->value) {
            $violations[] = 'Level 3 instructors are strictly paired with Advanced students.';
        }
    }

    /**
     * Get detailed validation errors without throwing exception
     */
    public function getValidationErrors(array $data): array
    {
        $violations = [];

        $this->validateLevel1Rules($data, $violations);
        $this->validateLevel2Rules($data, $violations);
        $this->validateLevel3Rules($data, $violations);
        $this->validateAgeRules($data, $violations);
        $this->validateBeginnerZoneRules($data, $violations);
        $this->validateCertificationMatchRules($data, $violations);

        return $violations;
    }

    /**
     * Check if booking would pass safety validation
     */
    public function wouldPassValidation(array $data): bool
    {
        return empty($this->getValidationErrors($data));
    }

    /**
     * Get applicable rules for display
     */
    public function getApplicableRules(int $instructorLevel, ?string $skillLevel = null): array
    {
        $rules = [];

        switch ($instructorLevel) {
            case 1:
                $rules = [
                    'max_students' => 2,
                    'allowed_skill_levels' => ['beginner'],
                    'allowed_spots' => ['beginner'],
                ];
                break;
            case 2:
                $rules = [
                    'max_students' => 1,
                    'allowed_skill_levels' => ['intermediate'],
                    'allowed_spots' => ['beginner', 'intermediate', 'advanced'],
                ];
                break;
            case 3:
                $rules = [
                    'max_students' => 5,
                    'allowed_skill_levels' => ['advanced'],
                    'allowed_spots' => ['beginner', 'intermediate', 'advanced'],
                    'can_teach_children' => true,
                ];
                break;
        }

        return $rules;
    }

    /**
     * Log safety validation attempt
     */
    private function logValidation(array $data, ?int $userId, bool $passed, array $violations): void
    {
        try {
            SafetyValidationLog::create([
                'user_id' => $userId,
                'booking_id' => $data['booking_id'] ?? null,
                'rule_violated' => !empty($violations) ? implode(', ', $violations) : null,
                'data_snapshot' => $data,
                'passed' => $passed,
                'error_message' => !empty($violations) ? implode('; ', $violations) : null,
            ]);
        } catch (\Exception $e) {
            // Log error but don't stop execution
            \Log::error('Failed to log safety validation: ' . $e->getMessage());
        }
    }

    /**
     * Validate that instructor can handle the group size
     */
    public function canHandleGroupSize(int $instructorLevel, int $studentCount): bool
    {
        $maxStudents = match ($instructorLevel) {
            1 => 2,
            2 => 1,
            3 => 5,
            default => 1,
        };

        return $studentCount <= $maxStudents;
    }

    /**
     * Check if instructor can teach specific skill level
     */
    public function canTeachSkillLevel(int $instructorLevel, string $skillLevel): bool
    {
        return match ($instructorLevel) {
            1 => $skillLevel === SkillLevel::Beginner->value,
            2 => $skillLevel === SkillLevel::Intermediate->value,
            3 => $skillLevel === SkillLevel::Advanced->value,
            default => false,
        };
    }
}