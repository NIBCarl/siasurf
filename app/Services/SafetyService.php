<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\InstructorLevel;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
use App\Exceptions\SafetyViolationException;
use App\Models\Booking;
use App\Models\SafetyValidationLog;

class SafetyService
{
    /**
     * Validate all safety rules for a booking.
     *
     * @throws SafetyViolationException
     */
    public function validateBookingSafety(array $data, ?int $userId = null): void
    {
        $violations = [];

        // Rule 1: Level 1 instructor - 1-on-1 only (beginners only, Teaching)
        $this->validateLevel1Rules($data, $violations);

        // Rule 2: Level 2 instructor - 1 beginner OR up to 2 intermediate students
        $this->validateLevel2Rules($data, $violations);

        // Rule 3: Level 3 instructor - 1–3 students of the same skill level
        $this->validateLevel3Rules($data, $violations);

        // Rule 4: Age < 5 rejected; age 5–12 requires Level 3 instructor
        $this->validateAgeRules($data, $violations);

        // Rule 5: The chosen break must not be harder than the surfer's level
        $this->validateSurfSpotDifficultyRules($data, $violations);

        // Rule 6: Certification & service type must match lesson difficulty
        $this->validateCertificationMatchRules($data, $violations);

        // Log validation attempt
        $this->logValidation($data, $userId, empty($violations), $violations);

        if (! empty($violations)) {
            throw new SafetyViolationException(
                'Safety requirements not met: '.implode('; ', $violations)
            );
        }
    }

    /**
     * Validate safety for a group where each participant has their own age
     * and skill level.
     *
     * Rules:
     *  - All participants in a group lesson must have the same skill level.
     *  - Capacity depends on instructor level AND student skill level:
     *      L1 Beginner -> max 1
     *      L2 Beginner -> max 1, L2 Intermediate -> max 2
     *      L3 Beginner / Intermediate / Advanced -> max 3
     *  - Any minor aged 5–12 requires a Level 3 instructor; age < 5 is rejected.
     *  - Surf spot difficulty must not exceed the group's skill level.
     *  - Service type (Teaching / Guiding / Coaching) must be authorized for
     *    both the instructor's level and the students' skill level.
     *
     * @param  array<int, array{age?: int|null, skill_level?: string|\App\Enums\SkillLevel}>  $participants
     * @throws SafetyViolationException
     */
    public function validateParticipants(
        array $participants,
        int $instructorLevel,
        string $surfSpotDifficulty,
        ?int $userId = null,
        ?SessionType $sessionType = null
    ): void {
        $violations = [];
        $count = count($participants);
        $levelEnum = InstructorLevel::tryFrom($instructorLevel) ?? InstructorLevel::Level1;

        $skills = [];
        foreach ($participants as $participant) {
            $rawSkill = $participant['skill_level'] ?? SkillLevel::Beginner;
            $skillEnum = $rawSkill instanceof SkillLevel
                ? $rawSkill
                : (SkillLevel::tryFrom((string) $rawSkill) ?? SkillLevel::Beginner);
            $skills[] = $skillEnum->value;
        }

        $uniqueSkills = array_values(array_unique($skills));
        if (count($uniqueSkills) > 1) {
            $violations[] = 'All students in a group session must be the same skill level.';
        }

        $groupSkill = SkillLevel::mostRestrictive($skills);

        // Check whether this instructor level is certified for the group's skill.
        if (! $groupSkill->canBeTaughtBy($instructorLevel)) {
            $violations[] = $groupSkill->label().' lessons require '.$groupSkill->instructorLevelRangeLabel().'.';
        }

        // Skill-aware capacity check.
        $max = $levelEnum->maxStudentsFor($groupSkill);
        if ($max > 0 && $count > $max) {
            if ($instructorLevel === 1) {
                $violations[] = 'Level 1 instructors can only do 1-on-1 sessions (maximum 1 beginner student).';
            } elseif ($instructorLevel === 2 && $groupSkill === SkillLevel::Beginner) {
                $violations[] = 'Level 2 instructors can only take 1 beginner student per session (or up to 2 intermediate students).';
            } else {
                $violations[] = "Level {$instructorLevel} instructors can handle maximum {$max} {$groupSkill->value} students.";
            }
        }

        // Service type authorization.
        if ($sessionType !== null) {
            if (! $sessionType->canBeDeliveredBy($instructorLevel)) {
                $violations[] = "Level {$instructorLevel} instructors are not certified for {$sessionType->label()} sessions.";
            }
            if (! $sessionType->canBeBookedBy($groupSkill)) {
                $violations[] = "{$groupSkill->label()} surfers cannot book {$sessionType->label()} sessions.";
            }
        }

        // Surf spot difficulty must not exceed the group's level.
        if (! $groupSkill->canSurfDifficulty($surfSpotDifficulty)) {
            $violations[] = $groupSkill->label().' surfers cannot book '
                .$surfSpotDifficulty.' surf spots. '
                .$groupSkill->surfSpotRuleLabel();
        }

        foreach ($participants as $participant) {
            $age = isset($participant['age']) && $participant['age'] !== '' ? (int) $participant['age'] : null;

            if ($age !== null && $age < 5) {
                $violations[] = 'Students must be at least 5 years old.';
            }

            if ($age !== null && $age >= 5 && $age <= 12 && $instructorLevel !== 3) {
                $violations[] = 'For safety, students aged 5-12 must be taught by a Level 3 Instructor (Expert) or a team of 2 instructors. Please choose a Level 3 instructor for this age group.';
            }
        }

        $violations = array_values(array_unique($violations));

        $this->logValidation([
            'instructor_level' => $instructorLevel,
            'session_type' => $sessionType?->value,
            'student_count' => $count,
            'participants' => array_map(fn ($p) => [
                'age' => $p['age'] ?? null,
                'skill_level' => $p['skill_level'] ?? null,
            ], $participants),
            'surf_spot_difficulty' => $surfSpotDifficulty,
        ], $userId, empty($violations), $violations);

        if (! empty($violations)) {
            throw new SafetyViolationException(
                'Safety requirements not met: '.implode('; ', $violations)
            );
        }
    }

    /**
     * Rule 1: Level 1 instructor - 1-on-1 only (max 1 beginner student, Teaching).
     */
    private function validateLevel1Rules(array $data, array &$violations): void
    {
        if (! isset($data['instructor_level']) || (int) $data['instructor_level'] !== 1) {
            return;
        }

        if (isset($data['student_count']) && (int) $data['student_count'] > 1) {
            $violations[] = 'Level 1 instructors can only do 1-on-1 sessions (maximum 1 student).';
        }

        if (isset($data['skill_level']) && $this->skillValue($data['skill_level']) !== SkillLevel::Beginner->value) {
            $violations[] = 'Level 1 instructors can only teach beginners.';
        }
    }

    /**
     * Rule 2: Level 2 instructor - 1 beginner OR up to 2 intermediate students.
     */
    private function validateLevel2Rules(array $data, array &$violations): void
    {
        if (! isset($data['instructor_level']) || (int) $data['instructor_level'] !== 2) {
            return;
        }

        $skill = isset($data['skill_level']) ? $this->skillValue($data['skill_level']) : null;
        $count = isset($data['student_count']) ? (int) $data['student_count'] : 1;

        if ($skill === SkillLevel::Beginner->value && $count > 1) {
            $violations[] = 'Level 2 instructors can only take 1 beginner student per session (or up to 2 intermediate students).';
        } elseif ($count > 2) {
            $violations[] = 'Level 2 instructors can handle maximum 2 intermediate students.';
        }
    }

    /**
     * Rule 3: Level 3 instructor - 1–3 students of the same skill level.
     */
    private function validateLevel3Rules(array $data, array &$violations): void
    {
        if (! isset($data['instructor_level']) || (int) $data['instructor_level'] !== 3) {
            return;
        }

        if (isset($data['student_count']) && (int) $data['student_count'] > 3) {
            $violations[] = 'Level 3 instructors can handle maximum 3 students of the same skill level.';
        }
    }

    /**
     * Rule 4: Age < 5 rejected; children age 5-12 require a Level 3 instructor.
     */
    private function validateAgeRules(array $data, array &$violations): void
    {
        if (! isset($data['student_age'])) {
            return;
        }

        $age = (int) $data['student_age'];

        if ($age < 5) {
            $violations[] = 'Students must be at least 5 years old.';

            return;
        }

        if ($age >= 5 && $age <= 12 && isset($data['instructor_level']) && (int) $data['instructor_level'] !== 3) {
            $violations[] = 'For safety, students aged 5-12 must be taught by a Level 3 Instructor (Expert) or a team of 2 instructors. Please choose a Level 3 instructor for this age group.';
        }
    }

    /**
     * Rule 5: the surf spot must not be harder than the surfer's own level.
     */
    private function validateSurfSpotDifficultyRules(array $data, array &$violations): void
    {
        if (! isset($data['skill_level'], $data['surf_spot_difficulty'])) {
            return;
        }

        $skill = $data['skill_level'] instanceof SkillLevel
            ? $data['skill_level']
            : SkillLevel::tryFrom((string) $data['skill_level']);

        if ($skill === null) {
            return;
        }

        if (! $skill->canSurfDifficulty((string) $data['surf_spot_difficulty'])) {
            $violations[] = $skill->label().' surfers cannot book '
                .$data['surf_spot_difficulty'].' surf spots. '
                .$skill->surfSpotRuleLabel();
        }
    }

    /**
     * Rule 6: Certification & service type must match lesson difficulty.
     */
    private function validateCertificationMatchRules(array $data, array &$violations): void
    {
        if (! isset($data['instructor_level'])) {
            return;
        }

        $instructorLevel = (int) $data['instructor_level'];

        if (isset($data['skill_level'])) {
            $skill = $data['skill_level'] instanceof SkillLevel
                ? $data['skill_level']
                : SkillLevel::tryFrom((string) $data['skill_level']);

            if ($skill !== null && ! $skill->canBeTaughtBy($instructorLevel)) {
                $violations[] = $skill->label().' lessons require '.$skill->instructorLevelRangeLabel().'.';
            }
        }

        if (isset($data['session_type'])) {
            $service = $data['session_type'] instanceof SessionType
                ? $data['session_type']
                : SessionType::tryFrom((string) $data['session_type']);

            if ($service !== null) {
                if (! $service->canBeDeliveredBy($instructorLevel)) {
                    $violations[] = "Level {$instructorLevel} instructors are not certified for {$service->label()} sessions.";
                }

                if (isset($data['skill_level'])) {
                    $skill = $data['skill_level'] instanceof SkillLevel
                        ? $data['skill_level']
                        : SkillLevel::tryFrom((string) $data['skill_level']);

                    if ($skill !== null && ! $service->canBeBookedBy($skill)) {
                        $violations[] = "{$skill->label()} surfers cannot book {$service->label()} sessions.";
                    }
                }
            }
        }
    }

    /**
     * Get detailed validation errors without throwing exception.
     */
    public function getValidationErrors(array $data): array
    {
        $violations = [];

        $this->validateLevel1Rules($data, $violations);
        $this->validateLevel2Rules($data, $violations);
        $this->validateLevel3Rules($data, $violations);
        $this->validateAgeRules($data, $violations);
        $this->validateSurfSpotDifficultyRules($data, $violations);
        $this->validateCertificationMatchRules($data, $violations);

        return array_values(array_unique($violations));
    }

    /**
     * Check if booking would pass safety validation.
     */
    public function wouldPassValidation(array $data): bool
    {
        return empty($this->getValidationErrors($data));
    }

    /**
     * Get applicable rules for display.
     */
    public function getApplicableRules(int $instructorLevel, ?string $skillLevel = null): array
    {
        $levelEnum = InstructorLevel::tryFrom($instructorLevel) ?? InstructorLevel::Level1;
        $skillEnum = $skillLevel ? SkillLevel::tryFrom($skillLevel) : null;

        $maxStudents = $skillEnum !== null
            ? $levelEnum->maxStudentsFor($skillEnum)
            : $levelEnum->maxStudents();

        return [
            'max_students' => $maxStudents,
            'allowed_skill_levels' => $levelEnum->allowedSkillLevels(),
            'allowed_spots' => $skillEnum?->allowedSurfSpotDifficulties() ?? $levelEnum->allowedSkillLevels(),
            'allowed_services' => array_map(fn (SessionType $s) => $s->value, $levelEnum->allowedServices()),
            'same_level_required' => true,
            'can_teach_children' => $instructorLevel === 3,
        ];
    }

    /**
     * Log safety validation attempt.
     */
    private function logValidation(array $data, ?int $userId, bool $passed, array $violations): void
    {
        try {
            SafetyValidationLog::create([
                'user_id' => $userId,
                'booking_id' => $data['booking_id'] ?? null,
                'rule_violated' => ! empty($violations) ? implode(', ', $violations) : 'none',
                'data_snapshot' => $data,
                'passed' => $passed,
                'error_message' => ! empty($violations) ? implode('; ', $violations) : null,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to log safety validation: '.$e->getMessage());
        }
    }

    /**
     * Validate that instructor can handle the group size for the given skill level.
     */
    public function canHandleGroupSize(int $instructorLevel, int $studentCount, ?string $skillLevel = null): bool
    {
        $levelEnum = InstructorLevel::tryFrom($instructorLevel) ?? InstructorLevel::Level1;
        $skillEnum = $skillLevel ? SkillLevel::tryFrom($skillLevel) : null;

        $maxStudents = $skillEnum !== null
            ? $levelEnum->maxStudentsFor($skillEnum)
            : $levelEnum->maxStudents();

        return $maxStudents > 0 && $studentCount <= $maxStudents;
    }

    /**
     * Check if instructor can teach specific skill level.
     */
    public function canTeachSkillLevel(int $instructorLevel, string $skillLevel): bool
    {
        $skill = SkillLevel::tryFrom($skillLevel);

        return $skill !== null && $skill->canBeTaughtBy($instructorLevel);
    }

    /**
     * Check if an instructor already has a booking that overlaps with the given time slot.
     */
    public function hasTimeConflict(
        int $instructorId,
        string $date,
        string $startTime,
        string $endTime,
        ?int $excludeBookingId = null
    ): bool {
        $query = Booking::where('instructor_id', $instructorId)
            ->where('date', $date)
            ->whereNotIn('status', [
                BookingStatus::Cancelled,
                BookingStatus::NoShow,
                BookingStatus::Expired,
                BookingStatus::Declined,
                BookingStatus::PaymentTimeout,
            ])
            ->where(function ($q) use ($startTime, $endTime) {
                $q->whereBetween('start_time', [$startTime, $endTime])
                    ->orWhereBetween('end_time', [$startTime, $endTime])
                    ->orWhere(function ($q2) use ($startTime, $endTime) {
                        $q2->where('start_time', '<=', $startTime)
                            ->where('end_time', '>=', $endTime);
                    });
            });

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        return $query->exists();
    }

    /**
     * Get all booked time slots for an instructor on a given date.
     */
    public function getBookedSlots(int $instructorId, string $date): array
    {
        return Booking::where('instructor_id', $instructorId)
            ->where('date', $date)
            ->whereNotIn('status', [
                BookingStatus::Cancelled,
                BookingStatus::NoShow,
                BookingStatus::Expired,
                BookingStatus::Declined,
                BookingStatus::PaymentTimeout,
            ])
            ->get(['start_time', 'end_time'])
            ->map(fn ($b) => [
                'start_time' => $b->start_time,
                'end_time' => $b->end_time,
            ])
            ->toArray();
    }

    private function skillValue(string|SkillLevel $skill): string
    {
        return $skill instanceof SkillLevel ? $skill->value : $skill;
    }
}
