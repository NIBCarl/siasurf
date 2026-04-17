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

    public function maxStudents(): int
    {
        return match($this) {
            self::Level1 => 2,
            self::Level2 => 1,
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
}
