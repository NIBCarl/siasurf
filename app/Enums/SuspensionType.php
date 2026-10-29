<?php

namespace App\Enums;

enum SuspensionType: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
    case Legacy = 'legacy';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual suspension by SISA',
            self::Automatic => 'Automatic 3-strike suspension',
            self::Legacy => 'Legacy suspension',
        };
    }
}
