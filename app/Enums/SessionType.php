<?php

namespace App\Enums;

enum SessionType: string
{
    case Teaching = 'teaching';
    case Guiding = 'guiding';
    case Coaching = 'coaching';

    public function label(): string
    {
        return match ($this) {
            self::Teaching => 'Teaching',
            self::Guiding => 'Guiding',
            self::Coaching => 'Coaching',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Teaching => 'First-timer or complete beginner learning surf fundamentals.',
            self::Guiding => 'Surfer who needs lineup guidance and in-water assistance.',
            self::Coaching => 'Surfer working on technique, maneuvers, and surf skills.',
        };
    }

    public function profileColumn(): string
    {
        return match ($this) {
            self::Teaching => 'rate_per_hour',
            self::Guiding => 'guiding_rate',
            self::Coaching => 'coaching_rate',
        };
    }

    public function minRate(): float
    {
        return (float) config("siasurf.services.{$this->value}.min_rate", match ($this) {
            self::Teaching => 600,
            self::Guiding => 800,
            self::Coaching => 1500,
        });
    }

    public function maxRate(): float
    {
        return (float) config("siasurf.services.{$this->value}.max_rate", match ($this) {
            self::Teaching => 1000,
            self::Guiding => 1500,
            self::Coaching => 2000,
        });
    }

    public function defaultRate(): float
    {
        return (float) config("siasurf.services.{$this->value}.default_rate", $this->minRate());
    }

    public function rateRangeRule(): string
    {
        return 'min:'.$this->minRate().'|max:'.$this->maxRate();
    }

    public function rateRangeLabel(): string
    {
        return '₱'.number_format($this->minRate()).'–₱'.number_format($this->maxRate());
    }

    /**
     * @return array<int, int>
     */
    public function allowedInstructorLevels(): array
    {
        return match ($this) {
            self::Teaching => [1, 2, 3],
            self::Guiding => [2, 3],
            self::Coaching => [3],
        };
    }

    /**
     * @return array<int, string>
     */
    public function allowedSkillLevels(): array
    {
        return match ($this) {
            self::Teaching => [SkillLevel::Beginner->value],
            self::Guiding => [SkillLevel::Intermediate->value, SkillLevel::Advanced->value],
            self::Coaching => [
                SkillLevel::Beginner->value,
                SkillLevel::Intermediate->value,
                SkillLevel::Advanced->value,
            ],
        };
    }

    public function canBeDeliveredBy(int $instructorLevel): bool
    {
        return in_array($instructorLevel, $this->allowedInstructorLevels(), true);
    }

    public function canBeBookedBy(SkillLevel $skill): bool
    {
        return in_array($skill->value, $this->allowedSkillLevels(), true);
    }

    /**
     * Natural default service for a student + instructor pairing.
     *
     * Beginners always default to Teaching (even on Level 3) so they pay the
     * Teaching rate rather than Coaching unless they explicitly choose Coaching.
     */
    public static function recommendedFor(SkillLevel $skill, int $instructorLevel): self
    {
        return match ($skill) {
            SkillLevel::Beginner => self::Teaching,
            SkillLevel::Intermediate => self::Guiding,
            SkillLevel::Advanced => $instructorLevel >= 3 ? self::Coaching : self::Guiding,
        };
    }

    /**
     * Eligible services a student of `$skill` may book with an instructor at `$instructorLevel`.
     *
     * @return array<int, self>
     */
    public static function optionsFor(SkillLevel $skill, int $instructorLevel): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $service) => $service->canBeDeliveredBy($instructorLevel) && $service->canBeBookedBy($skill)
        ));
    }

    /**
     * Services a student of `$skill` is eligible to book (regardless of instructor).
     *
     * @return array<int, self>
     */
    public static function bookableBy(SkillLevel $skill): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $service) => $service->canBeBookedBy($skill)
        ));
    }
}
