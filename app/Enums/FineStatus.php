<?php

namespace App\Enums;

enum FineStatus: string
{
    case Due = 'due';
    case Paid = 'paid';
    case Waived = 'waived';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Due => 'Due (Payable to SISA)',
            self::Paid => 'Paid',
            self::Waived => 'Waived by SISA',
            self::Voided => 'Voided',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Due;
    }
}
