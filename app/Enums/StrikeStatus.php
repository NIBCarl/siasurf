<?php

namespace App\Enums;

enum StrikeStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Served = 'served';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Revoked => 'Revoked',
            self::Served => 'Served',
        };
    }
}
