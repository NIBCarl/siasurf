<?php

namespace App\Enums;

enum ChangeRequestType: string
{
    case Reschedule = 'reschedule';
    case Cancellation = 'cancellation';

    public function label(): string
    {
        return match($this) {
            self::Reschedule => 'Reschedule',
            self::Cancellation => 'Cancellation',
        };
    }
}
