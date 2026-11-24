<?php

namespace App\Enums;

enum ChangeRequestStatus: string
{
    case AwaitingStudent = 'awaiting_student';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case AutoApproved = 'auto_approved';

    public function label(): string
    {
        return match($this) {
            self::AwaitingStudent => 'Awaiting Student Agreement',
            self::Pending => 'Awaiting Admin Approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::AutoApproved => 'Applied',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::AwaitingStudent => 'amber',
            self::Pending => 'amber',
            self::Approved => 'green',
            self::Rejected => 'red',
            self::AutoApproved => 'blue',
        };
    }

    /**
     * Whether the request is still waiting on a decision.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::AwaitingStudent], true);
    }
}
