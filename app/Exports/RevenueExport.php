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
        return Payment::with(['booking.instructor', 'booking.student'])
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function map($payment): array
    {
        $method = $payment->payment_method instanceof \App\Enums\PaymentMethod
            ? $payment->payment_method->label()
            : (string) ($payment->payment_method ?? 'N/A');

        $status = $payment->status instanceof \App\Enums\PaymentStatus
            ? $payment->status->label()
            : (string) ($payment->status ?? 'Pending');

        return [
            $payment->created_at?->format('Y-m-d') ?? 'N/A',
            $payment->id,
            $payment->booking?->instructor?->name ?? 'N/A',
            $payment->booking?->student?->name ?? 'N/A',
            '₱' . number_format((float) $payment->amount, 2),
            $method,
            $status,
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
