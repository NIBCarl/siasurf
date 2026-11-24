<?php

namespace App\Enums;

enum IncidentReportTopic: string
{
    case UnsafeSurfZone = 'unsafe_surf_zone';
    case NoSafetyProtocol = 'no_safety_protocol';
    case UnprofessionalConduct = 'unprofessional_conduct';
    case ServiceComplaint = 'service_complaint';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::UnsafeSurfZone => 'Unsafe surf zone or conditions',
            self::NoSafetyProtocol => 'No safety protocol or supervision',
            self::UnprofessionalConduct => 'Unprofessional conduct',
            self::ServiceComplaint => 'Service or booking complaint',
            self::Other => 'Other concern',
        };
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $topic) => [
                'value' => $topic->value,
                'label' => $topic->label(),
            ])
            ->values()
            ->all();
    }
}
