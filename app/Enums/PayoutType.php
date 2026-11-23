<?php

namespace App\Enums;

enum PayoutType: string
{
    case Payout = 'payout';
    case Remittance = 'remittance';
    case Offset = 'offset';

    public function label(): string
    {
        return match ($this) {
            self::Payout => 'In-Person Payout',
            self::Remittance => 'Cash Fee Remittance',
            self::Offset => 'Zero-Cash Offset',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Payout => 'SISA paid out net GCash earnings to the instructor after deducting cash service fees owed.',
            self::Remittance => 'Instructor remitted net cash service fees owed to SISA in person.',
            self::Offset => 'GCash earnings and cash service fees owed cancelled out with zero net cash movement.',
        };
    }
}
