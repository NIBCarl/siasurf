<?php

namespace App\Enums;

enum CertificateType: string
{
    case BLS = 'bls'; // Basic Life Support
    case WaSAR = 'wasar'; // Water Safety and Rescue
    case SurfSkill = 'surf_skill'; // Surf Skill Certificate
    case ISA = 'isa'; // International Surfing Association
    case SISA = 'sisa'; // Siargao Island Surfing Association

    public function label(): string
    {
        return match($this) {
            self::BLS => 'Basic Life Support (BLS)',
            self::WaSAR => 'Water Safety and Rescue (WaSAR)',
            self::SurfSkill => 'Surf Skill Certificate',
            self::ISA => 'International Surfing Association (ISA)',
            self::SISA => 'Siargao Island Surfing Association (SISA)',
        };
    }

    public function isRequired(): bool
    {
        return match($this) {
            self::BLS, self::WaSAR, self::SISA => true,
            self::SurfSkill, self::ISA => false,
        };
    }
}
