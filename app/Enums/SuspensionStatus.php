<?php

namespace App\Enums;

enum SuspensionStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case LiftedOnAppeal = 'lifted_on_appeal';
    case RevokedByAdmin = 'revoked_by_admin';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::LiftedOnAppeal => 'Lifted on appeal',
            self::RevokedByAdmin => 'Revoked by SISA',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
