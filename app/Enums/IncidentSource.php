<?php

namespace App\Enums;

enum IncidentSource: string
{
    case Admin = 'admin';
    case StudentFeedback = 'student_feedback';
    case System = 'system';
    case Legacy = 'legacy';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'SISA Admin',
            self::StudentFeedback => 'Student Feedback',
            self::System => 'System',
            self::Legacy => 'Legacy Record',
        };
    }
}
