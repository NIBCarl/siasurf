<?php

namespace App\Enums;

enum SkillLevel: string
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';

    public function label(): string
    {
        return match($this) {
            self::Beginner => 'Beginner',
            self::Intermediate => 'Intermediate',
            self::Advanced => 'Advanced',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::Beginner => 'First time or limited surfing experience',
            self::Intermediate => 'Can ride waves independently, working on techniques',
            self::Advanced => 'Experienced surfer, can handle challenging conditions',
        };
    }
}
