<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending SISA Review',
            self::Confirmed => 'Confirmed',
            self::Rejected => 'Rejected',
            self::Revoked => 'Revoked',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Confirmed => 'red',
            self::Rejected => 'gray',
            self::Revoked => 'gray',
            self::Withdrawn => 'gray',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Rejected, self::Revoked, self::Withdrawn], true);
    }
}
