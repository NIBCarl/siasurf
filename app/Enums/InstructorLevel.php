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
            self::Level1 => 'Certified to teach beginner students only. Maximum 1 student per session (1-on-1).',
            self::Level2 => 'Certified to teach all skill levels. Maximum 2 students per session.',
            self::Level3 => 'Master instructor certified to teach all skill levels and group sessions. Maximum 5 students.',
        };
    }

    public function maxStudents(): int
    {
        return match($this) {
            self::Level1 => 1,
            self::Level2 => 2,
            self::Level3 => 5,
        };
    }

    public function allowedSkillLevels(): array
    {
        return match($this) {
            self::Level1 => [SkillLevel::Beginner->value],
            self::Level2 => [SkillLevel::Beginner->value, SkillLevel::Intermediate->value, SkillLevel::Advanced->value],
            self::Level3 => [SkillLevel::Beginner->value, SkillLevel::Intermediate->value, SkillLevel::Advanced->value],
        };
    }

    /**
     * How many students may share one time slot with this instructor.
     *
     * This is the capacity the availability engine sums `student_count`
     * against. It is the same hard safety ceiling enforced by SafetyService:
     *
     *  - Level 1 -> 1 student (1-on-1 only)
     *  - Level 2 -> 2 students
     *  - Level 3 -> 5 students (may be split across several bookings)
     */
    public function slotCapacity(): int
    {
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
