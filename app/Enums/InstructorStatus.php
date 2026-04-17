<?php

namespace App\Enums;

enum InstructorStatus: string
{
    case PendingVerification = 'pending_verification';
    case Active = 'active';
    case Suspended = 'suspended';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match($this) {
            self::PendingVerification => 'Pending Verification',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Inactive => 'Inactive',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::PendingVerification => 'yellow',
            self::Active => 'green',
            self::Suspended => 'red',
            self::Inactive => 'gray',
        };
    }

    public function isVisible(): bool
    {
        return $this === self::Active;
    }
}
