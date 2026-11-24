<?php

namespace App\Enums;

enum IncidentOutcome: string
{
    case NoStrike = 'no_strike';
    case OneStrike = '1_strike';
    case TwoStrikes = '2_strikes';
    case ThreeStrikes = '3_strikes';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::NoStrike => 'No strike (Dismissed)',
            self::OneStrike => '1 Strike',
            self::TwoStrikes => '2 Strikes',
            self::ThreeStrikes => '3 Strikes',
            self::Critical => 'Critical (Handled outside system)',
        };
    }

    public function strikes(): int
    {
        return match ($this) {
            self::OneStrike => 1,
            self::TwoStrikes => 2,
            self::ThreeStrikes => 3,
            self::NoStrike, self::Critical => 0,
        };
    }

    public function severity(): ?IncidentSeverity
    {
        return match ($this) {
            self::NoStrike => null,
            self::OneStrike => IncidentSeverity::Minor,
            self::TwoStrikes, self::ThreeStrikes => IncidentSeverity::Major,
            self::Critical => IncidentSeverity::Critical,
        };
    }

    public function precedence(): int
    {
        return match ($this) {
            self::NoStrike => 0,
            self::OneStrike => 1,
            self::TwoStrikes => 2,
            self::ThreeStrikes => 3,
            self::Critical => 4,
        };
    }

    public function isCritical(): bool
    {
        return $this === self::Critical;
    }

    public function isDismissal(): bool
    {
        return $this === self::NoStrike;
    }

    public function appliesStrike(): bool
    {
        return $this->strikes() > 0;
    }

    /**
     * Pick the single highest-precedence outcome across checked items (never sum).
     *
     * @param  iterable<int, self|null>  $outcomes
     */
    public static function highest(iterable $outcomes): ?self
    {
        $best = null;

        foreach ($outcomes as $outcome) {
            if (! $outcome instanceof self) {
                continue;
            }

            if ($best === null || $outcome->precedence() > $best->precedence()) {
                $best = $outcome;
            }
        }

        return $best;
    }

    public static function fromLegacySeverity(?IncidentSeverity $severity): ?self
    {
        return match ($severity) {
            IncidentSeverity::Minor => self::OneStrike,
            IncidentSeverity::Major => self::TwoStrikes,
            IncidentSeverity::Critical => self::Critical,
            null => null,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function decisionOptions(bool $includeDismissal = true): array
    {
        return collect(self::cases())
            ->filter(fn (self $outcome) => $includeDismissal || ! $outcome->isDismissal())
            ->map(fn (self $outcome) => [
                'value' => $outcome->value,
                'label' => $outcome->label(),
                'strikes' => $outcome->strikes(),
                'is_critical' => $outcome->isCritical(),
                'is_dismissal' => $outcome->isDismissal(),
            ])
            ->values()
            ->all();
    }
}
