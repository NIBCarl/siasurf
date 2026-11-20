<?php

namespace App\Enums;

enum InstructorStatus: string
{
    case PendingVerification = 'pending_verification';
    case Active = 'active';
    case Suspended = 'suspended';
    case Inactive = 'inactive';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match($this) {
            self::PendingVerification => 'Pending Verification',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Inactive => 'Inactive',
            self::Rejected => 'Rejected',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::PendingVerification => 'yellow',
            self::Active => 'green',
            self::Suspended => 'red',
            self::Inactive => 'gray',
            self::Rejected => 'red',
        };
    }

    public function isVisible(): bool
    {
        return $this === self::Active;
    }
}
