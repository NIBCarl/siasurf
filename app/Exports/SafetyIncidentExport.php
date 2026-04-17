<?php

namespace App\Exports;

use App\Models\SafetyIncident;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class SafetyIncidentExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize, WithMapping
{
    protected $startDate;
    protected $endDate;

    public function __construct($startDate = null, $endDate = null)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function collection()
    {
        $query = SafetyIncident::with(['instructor', 'booking', 'reportedBy'])
            ->orderBy('created_at', 'desc');

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('created_at', [$this->startDate, $this->endDate]);
        }

        return $query->get();
    }

    public function map($incident): array
    {
        return [
            $incident->id,
            $incident->created_at->format('Y-m-d H:i:s'),
            $incident->type->label(),
            $incident->severity->label(),
            $incident->instructor->name ?? 'N/A',
            $incident->booking_id ?? 'N/A',
            $incident->location ?? 'N/A',
            $incident->description,
            $incident->reportedBy->name ?? 'System',
            $this->getSeverityColor($incident->severity->value),
        ];
    }

    public function headings(): array
    {
        return [
            'Incident ID',
            'Date/Time',
            'Type',
            'Severity',
            'Instructor',
            'Booking ID',
            'Location',
            'Description',
            'Reported By',
            'Severity Level',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Header styling
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FEE2E2'],
            ],
        ];

        // Apply severity color coding
        $row = 2;
        foreach ($this->collection() as $incident) {
            $color = $this->getSeverityColorCode($incident->severity->value);
            $sheet->getStyle("A{$row}:J{$row}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB($color);
            $row++;
        }

        return [
            1 => $headerStyle,
        ];
    }

    private function getSeverityColor($severity): string
    {
        return match($severity) {
            'critical' => 'Critical',
            'major' => 'Major',
            'minor' => 'Minor',
            default => 'Unknown',
        };
    }

    private function getSeverityColorCode($severity): string
    {
        return match($severity) {
            'critical' => 'FECACA', // Light red
            'major' => 'FED7AA',    // Light orange
            'minor' => 'FEF08A',    // Light yellow
            default => 'FFFFFF',    // White
        };
    }
}
