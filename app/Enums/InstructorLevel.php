<?php

namespace App\Enums;

enum InstructorLevel: int
{
    case Level1 = 1;
    case Level2 = 2;
    case Level3 = 3;

    public function label(): string
    {
        return match($this) {
            self::Level1 => 'Level 1 (Beginner Instructor)',
            self::Level2 => 'Level 2 (Intermediate Instructor)',
            self::Level3 => 'Level 3 (Advanced Instructor)',
        };
    }

    public function shortLabel(): string
    {
        return match($this) {
            self::Level1 => 'Level 1',
            self::Level2 => 'Level 2',
            self::Level3 => 'Level 3',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::Level1 => 'Certified for Teaching beginner students only. Maximum 1 student per session (1-on-1).',
            self::Level2 => 'Certified for Teaching (1 beginner) or Guiding (up to 2 intermediate students of the same level).',
            self::Level3 => 'Master instructor certified for Teaching, Guiding, and Coaching (1–3 students of the same skill level).',
        };
    }

    public function maxStudents(): int
    {
        return match($this) {
            self::Level1 => 1,
            self::Level2 => 2,
            self::Level3 => 3,
        };
    }

    /**
     * Skill-aware maximum number of students allowed in one slot.
     *
     *  - Level 1: 1 beginner
     *  - Level 2: 1 beginner OR 2 intermediate
     *  - Level 3: 1–3 students of the same skill level (beginner, intermediate, or advanced)
     */
    public function maxStudentsFor(SkillLevel $skill): int
    {
        return match ($this) {
            self::Level1 => $skill === SkillLevel::Beginner ? 1 : 0,
            self::Level2 => match ($skill) {
                SkillLevel::Beginner => 1,
                SkillLevel::Intermediate => 2,
                SkillLevel::Advanced => 0,
            },
            self::Level3 => 3,
        };
    }

    public function allowedSkillLevels(): array
    {
        return match($this) {
            self::Level1 => [SkillLevel::Beginner->value],
            self::Level2 => [SkillLevel::Beginner->value, SkillLevel::Intermediate->value],
            self::Level3 => [SkillLevel::Beginner->value, SkillLevel::Intermediate->value, SkillLevel::Advanced->value],
        };
    }

    /**
     * Services this instructor certification level is authorized to offer.
     *
     * @return array<int, SessionType>
     */
    public function allowedServices(): array
    {
        return match ($this) {
            self::Level1 => [SessionType::Teaching],
            self::Level2 => [SessionType::Teaching, SessionType::Guiding],
            self::Level3 => [SessionType::Teaching, SessionType::Guiding, SessionType::Coaching],
        };
    }

    /**
     * String values for services this instructor certification level may offer.
     *
     * @return array<int, string>
     */
    public function allowedServiceValues(): array
    {
        return array_map(fn (SessionType $service) => $service->value, $this->allowedServices());
    }

    public function allowedServicesLabel(): string
    {
        return match ($this) {
            self::Level1 => 'Teaching',
            self::Level2 => 'Teaching and Guiding',
            self::Level3 => 'Teaching, Guiding, and Coaching',
        };
    }

    /**
     * Surf-zone difficulty ratings this instructor level is permitted to select.
     *
     * @return array<int, string>
     */
    public function allowedSurfSpotDifficulties(): array
    {
        return match ($this) {
            self::Level1 => [SkillLevel::Beginner->value],
            self::Level2 => [SkillLevel::Beginner->value, SkillLevel::Intermediate->value],
            self::Level3 => [
                SkillLevel::Beginner->value,
                SkillLevel::Intermediate->value,
                SkillLevel::Advanced->value,
            ],
        };
    }

    public function canTeachAtDifficulty(string $difficulty): bool
    {
        return in_array(strtolower($difficulty), $this->allowedSurfSpotDifficulties(), true);
    }

    public function surfZoneRuleLabel(): string
    {
        return match ($this) {
            self::Level1 => 'Level 1 instructors may select beginner surf zones only.',
            self::Level2 => 'Level 2 instructors may select beginner and intermediate surf zones.',
            self::Level3 => 'Level 3 instructors may select all surf zones (beginner, intermediate, and advanced).',
        };
    }

    /**
     * How many students may share one time slot with this instructor.
     *
     * When `$skill` is supplied, returns the exact skill-dependent ceiling
     * (e.g., Level 2 holds max 1 beginner or max 2 intermediates). Without a
     * skill, returns the instructor level's overall maximum seat count.
     */
    public function slotCapacity(?SkillLevel $skill = null): int
    {
        if ($skill !== null) {
            return $this->maxStudentsFor($skill);
        }

        return $this->maxStudents();
    }

    /**
     * Level 1 (1-on-1, capacity 1) holds the entire time slot from the moment
     * a student enters the waiver phase. Level 2 (max 2 seats) and Level 3
     * (max 5 seats) immediately occupy the student's booked seat count
     * (`student_count`) during the waiver phase, while leaving any remaining
     * unoccupied seats open for other students until all seats are full.
     */
    public function holdsSlotWhilePending(): bool
    {
        return $this === self::Level1;
    }

    /**
     * Whether several bookings may co-exist in the same time slot.
     */
    public function allowsParallelBookings(): bool
    {
        return $this->slotCapacity() > 1 && ! $this->holdsSlotWhilePending();
    }
}
