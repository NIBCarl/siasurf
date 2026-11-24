<?php

namespace App\Exports;

use App\Models\SafetyIncident;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SafetyIncidentExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
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
        $query = SafetyIncident::with(['instructor', 'booking', 'reporter'])
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
            $incident->created_at?->format('Y-m-d H:i:s') ?? 'N/A',
            $incident->type?->label() ?? 'N/A',
            $incident->suggested_outcome?->label() ?? ($incident->suggested_severity?->label() ?? 'Unclassified'),
            $incident->final_outcome?->label() ?? ($incident->severity?->label() ?? 'Pending'),
            $incident->isExternallyHandled() ? 'Yes (Handled Outside System)' : 'No',
            $incident->decision_reason ?? $incident->rejection_reason ?? '—',
            $incident->instructor?->name ?? 'N/A',
            $incident->booking_id ?? 'N/A',
            $incident->location ?? 'N/A',
            $incident->description,
            $incident->reporter?->name ?? 'System',
        ];
    }

    public function headings(): array
    {
        return [
            'Incident ID',
            'Date/Time',
            'Type',
            'Suggested Outcome (SISA-Only)',
            'Final Outcome',
            'Handled Externally',
            'Decision Reason',
            'Instructor',
            'Booking ID',
            'Location',
            'Description',
            'Reported By',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FEE2E2'],
            ],
        ];

        $row = 2;
        foreach ($this->collection() as $incident) {
            $severityValue = $incident->severity?->value ?? $incident->suggested_severity?->value ?? 'unknown';
            $color = $this->getSeverityColorCode($severityValue);
            $sheet->getStyle("A{$row}:L{$row}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB($color);
            $row++;
        }

        return [
            1 => $headerStyle,
        ];
    }

    private function getSeverityColorCode($severity): string
    {
        return match ($severity) {
            'critical' => 'FECACA',
            'major' => 'FED7AA',
            'minor' => 'FEF08A',
            default => 'FFFFFF',
        };
    }
}
