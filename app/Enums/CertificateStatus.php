<?php

namespace App\Enums;

enum CertificateStatus: string
{
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match($this) {
            self::PendingVerification => 'Pending Verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::PendingVerification => 'yellow',
            self::Verified => 'green',
            self::Rejected => 'red',
            self::Expired => 'gray',
        };
    }
}
