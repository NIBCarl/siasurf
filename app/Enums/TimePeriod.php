<?php

namespace App\Enums;

enum TimePeriod: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';

    public function label(): string
    {
        return match($this) {
            self::Morning => 'Morning (6AM - 12PM)',
            self::Afternoon => 'Afternoon (12PM - 6PM)',
        };
    }

    public function startTime(): string
    {
        return match($this) {
            self::Morning => '06:00',
            self::Afternoon => '12:00',
        };
    }

    public function endTime(): string
    {
        return match($this) {
            self::Morning => '12:00',
            self::Afternoon => '18:00',
        };
    }
}
