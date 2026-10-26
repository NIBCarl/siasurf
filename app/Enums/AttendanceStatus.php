<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';

    public function label(): string
    {
        return match($this) {
            self::Present => 'Present',
            self::Late => 'Late',
            self::Absent => 'Absent',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Present => 'green',
            self::Late => 'amber',
            self::Absent => 'red',
        };
    }

    /**
     * Whether the student actually showed up for the lesson.
     */
    public function countsAsAttended(): bool
    {
        return $this !== self::Absent;
    }
}
