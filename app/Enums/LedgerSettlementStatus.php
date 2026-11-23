<?php

namespace App\Enums;

enum LedgerSettlementStatus: string
{
    case Unsettled = 'unsettled';
    case Settled = 'settled';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Unsettled => 'Unsettled',
            self::Settled => 'Settled',
            self::Overdue => 'Overdue',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unsettled => 'amber',
            self::Settled => 'green',
            self::Overdue => 'red',
        };
    }
}
