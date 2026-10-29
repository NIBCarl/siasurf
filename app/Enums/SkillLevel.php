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

    /**
     * Instructor levels whose certification covers this student level.
     *
     * This is the inverse of InstructorLevel::allowedSkillLevels() and is the
     * single source of truth for directory visibility:
     *
     *  - Beginner     -> Levels 1, 2 and 3
     *  - Intermediate -> Levels 2 and 3 (Level 1 is beginner-only)
     *  - Advanced     -> Level 3 only (Expert)
     *
     * @return array<int, int>
     */
    public function instructorLevels(): array
    {
        return match($this) {
            self::Beginner => [1, 2, 3],
            self::Intermediate => [2, 3],
            self::Advanced => [3],
        };
    }

    /**
     * Whether an instructor at this level is allowed to teach the student.
     */
    public function canBeTaughtBy(int $instructorLevel): bool
    {
        return in_array($instructorLevel, $this->instructorLevels(), true);
    }

    /**
     * Human-readable summary used by the directory banner.
     */
    public function instructorLevelRangeLabel(): string
    {
        $levels = $this->instructorLevels();

        return count($levels) === 1
            ? 'Level ' . $levels[0] . ' instructors only'
            : 'Level ' . implode(', ', array_slice($levels, 0, -1)) . ' and ' . end($levels) . ' instructors';
    }

    /**
     * Surf-spot difficulties this level may book.
     *
     * A surfer may always use an easier break than their own level, never a
     * harder one:
     *
     *  - Beginner     -> beginner breaks only
     *  - Intermediate -> beginner + intermediate breaks
     *  - Advanced     -> beginner + intermediate + advanced breaks
     *
     * @return array<int, string>
     */
    public function allowedSurfSpotDifficulties(): array
    {
        return match($this) {
            self::Beginner => ['beginner'],
            self::Intermediate => ['beginner', 'intermediate'],
            self::Advanced => ['beginner', 'intermediate', 'advanced'],
        };
    }

    public function canSurfDifficulty(string $difficulty): bool
    {
        return in_array(strtolower($difficulty), $this->allowedSurfSpotDifficulties(), true);
    }

    /**
     * Numeric rank used to find the most restrictive skill in a group.
     */
    public function rank(): int
    {
        return match($this) {
            self::Beginner => 1,
            self::Intermediate => 2,
            self::Advanced => 3,
        };
    }

    /**
     * The lowest skill in a group. The whole lesson is planned for that level,
     * so the most restrictive participant decides which breaks are allowed.
     *
     * @param iterable<int, self|string> $levels
     */
    public static function mostRestrictive(iterable $levels): self
    {
        $ranked = [];

        foreach ($levels as $level) {
            $enum = $level instanceof self ? $level : self::tryFrom((string) $level);

            if ($enum !== null) {
                $ranked[] = $enum;
            }
        }

        if ($ranked === []) {
            return self::Beginner;
        }

        usort($ranked, fn (self $a, self $b) => $a->rank() <=> $b->rank());

        return $ranked[0];
    }

    /**
     * Human-readable description of the spot restriction.
     */
    public function surfSpotRuleLabel(): string
    {
        return match($this) {
            self::Beginner => 'Beginner surfers can only book beginner breaks.',
            self::Intermediate => 'Intermediate surfers can book beginner and intermediate breaks. Advanced breaks require an advanced level.',
            self::Advanced => 'Advanced surfers can book any break.',
        };
    }
}
