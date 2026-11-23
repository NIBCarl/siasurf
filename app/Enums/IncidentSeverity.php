<?php

namespace App\Enums;

enum IncidentSeverity: string
{
    case Minor = 'minor';
    case Major = 'major';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Minor => 'Minor',
            self::Major => 'Major',
            self::Critical => 'Critical',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Minor => 'yellow',
            self::Major => 'orange',
            self::Critical => 'red',
        };
    }

    /**
     * Critical incidents are handled outside the system by SISA/authorities
     * and do not automatically add strikes. IncidentOutcome is canonical for
     * SISA decisions (no_strike, 1_strike, 2_strikes, 3_strikes, critical).
     */
    public function strikes(): int
    {
        return match ($this) {
            self::Minor => 1,
            self::Major => 2,
            self::Critical => 0,
        };
    }
}
