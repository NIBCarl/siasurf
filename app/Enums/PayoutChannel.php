<?php

namespace App\Enums;

enum PayoutChannel: string
{
    case InPersonCash = 'in_person_cash';
    case InPersonGcash = 'in_person_gcash';
    case Offset = 'offset';

    public function label(): string
    {
        return match ($this) {
            self::InPersonCash => 'In-Person Cash (SISA Office)',
            self::InPersonGcash => 'In-Person GCash Transfer',
            self::Offset => 'Net Balance Offset',
        };
    }
}
