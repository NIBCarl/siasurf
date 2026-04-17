<?php

namespace App\Exports;

use App\Models\Booking;
use App\Enums\BookingStatus;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SessionLogExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize, WithMapping
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
        $query = Booking::with(['instructor', 'student', 'surfSpot', 'payment'])
            ->where('status', BookingStatus::Completed->value)
            ->orderBy('date', 'desc');

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('date', [$this->startDate, $this->endDate]);
        }

        return $query->get();
    }

    public function map($booking): array
    {
        $duration = 2; // Standard 2-hour sessions
        $ratePerHour = $booking->total_amount / $duration;

        return [
            $booking->id,
            $booking->date->format('Y-m-d'),
            $booking->time_period->label(),
            $booking->instructor->name,
            $booking->student->name,
            $booking->surfSpot->name ?? 'N/A',
            $booking->skill_level->label(),
            $booking->student_count,
            $duration . ' hours',
            '₱' . number_format($ratePerHour, 2) . '/hr',
            '₱' . number_format($booking->total_amount, 2),
            $booking->payment->status->label() ?? 'N/A',
            $booking->completed_at ? $booking->completed_at->format('Y-m-d H:i:s') : 'N/A',
        ];
    }

    public function headings(): array
    {
        return [
            'Booking ID',
            'Date',
            'Time Period',
            'Instructor',
            'Student',
            'Surf Spot',
            'Skill Level',
            'Student Count',
            'Duration',
            'Rate',
            'Total Amount',
            'Payment Status',
            'Completed At',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'DBEAFE'],
                ],
            ],
        ];
    }
}
