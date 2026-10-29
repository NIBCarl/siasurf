<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PendingCash = 'pending_cash';

    /**
     * The student uploaded a GCash receipt and it is waiting for the
     * instructor/SISA to verify it. This is what "payment submitted" means for
     * the cancel rules: the money may already have left the student's account.
     */
    case PendingVerification = 'pending_verification';

    public function label(): string
    {
        return match($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
            self::PendingCash => 'Pending Cash Payment',
            self::PendingVerification => 'Awaiting Receipt Verification',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending => 'yellow',
            self::Completed => 'green',
            self::Failed => 'red',
            self::Refunded => 'gray',
            self::PendingCash => 'orange',
            self::PendingVerification => 'blue',
        };
    }

    /**
     * Statuses that mean money has been handed over (or claimed) by the
     * student. Cancelling is blocked from this point on.
     *
     * @return array<int, self>
     */
    public static function submitted(): array
    {
        return [self::PendingVerification, self::Completed];
    }
}
