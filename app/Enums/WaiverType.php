<?php

namespace App\Enums;

enum WaiverType: string
{
    case Liability = 'liability';
    case ParentalConsent = 'parental_consent';

    public function label(): string
    {
        return match($this) {
            self::Liability => 'Liability Waiver',
            self::ParentalConsent => 'Parental Consent Form',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::Liability => 'Assumption of risk and release of liability',
            self::ParentalConsent => 'Parent/Guardian consent for minors under 18',
        };
    }
}
