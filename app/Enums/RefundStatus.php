<?php

namespace App\Enums;

enum RefundStatus: string
{
    case Requested = 'requested';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case ManualRequired = 'manual_required';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Refund Requested',
            self::Processing => 'Processing Refund',
            self::Completed => 'Refund Completed',
            self::Failed => 'Refund Failed',
            self::ManualRequired => 'Manual Refund Required',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Requested => 'amber',
            self::Processing => 'blue',
            self::Completed => 'green',
            self::Failed => 'red',
            self::ManualRequired => 'orange',
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Completed;
    }
}
