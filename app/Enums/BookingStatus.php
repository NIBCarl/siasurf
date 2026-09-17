<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match($this) {
            self::Pending => 'Pending',
            self::AwaitingPayment => 'Awaiting Payment',
            self::Confirmed => 'Confirmed',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No Show',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending => 'yellow',
            self::AwaitingPayment => 'amber',
            self::Confirmed => 'green',
            self::InProgress => 'blue',
            self::Completed => 'indigo',
            self::Cancelled => 'red',
            self::NoShow => 'gray',
        };
    }
}
