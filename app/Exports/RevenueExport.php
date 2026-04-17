<?php

namespace App\Exports;

use App\Models\Payment;
use App\Enums\PaymentStatus;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RevenueExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize, WithMapping
{
    protected $startDate;
    protected $endDate;

    public function __construct($startDate = null, $endDate = null)
    {
        $this->startDate = $startDate ?? now()->startOfMonth();
        $this->endDate = $endDate ?? now()->endOfMonth();
    }

    public function collection()
    {
        return Payment::with(['booking.instructor'])
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function map($payment): array
    {
        return [
            $payment->created_at->format('Y-m-d'),
            $payment->id,
            $payment->booking->instructor->name ?? 'N/A',
            $payment->booking->student->name ?? 'N/A',
            '₱' . number_format($payment->amount, 2),
            $payment->payment_method,
            $payment->status->label(),
            $payment->paid_at ? $payment->paid_at->format('Y-m-d H:i:s') : 'Pending',
        ];
    }

    public function headings(): array
    {
        return [
            'Date',
            'Payment ID',
            'Instructor',
            'Student',
            'Amount',
            'Payment Method',
            'Status',
            'Paid At',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'D1FAE5'],
                ],
            ],
        ];
    }
}
