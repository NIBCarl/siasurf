<?php

namespace App\Enums;

enum IncidentType: string
{
    case Injury = 'injury';
    case NearMiss = 'near_miss';
    case RuleViolation = 'rule_violation';

    public function label(): string
    {
        return match($this) {
            self::Injury => 'Injury',
            self::NearMiss => 'Near Miss',
            self::RuleViolation => 'Rule Violation',
        };
    }
}
