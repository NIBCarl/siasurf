<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class ReceiptService
{
    /**
     * Generate a PDF receipt for a booking
     */
    public function generateReceipt(Booking $booking): string
    {
        $booking->load(['student', 'instructor', 'surfSpot', 'payment']);

        $data = [
            'booking' => $booking,
            'receipt_number' => 'RCP-' . str_pad($booking->id, 6, '0', STR_PAD_LEFT),
            'date_issued' => now()->format('F d, Y'),
            'company_name' => config('app.name', 'SiaSurf'),
            'company_address' => 'Siargao Island, Surigao del Norte, Philippines',
            'company_email' => config('app.admin_email', 'admin@siasurf.com'),
        ];

        $pdf = PDF::loadView('pdfs.receipt', $data);
        $pdf->setPaper('A4');
        
        $filename = "receipts/booking_{$booking->id}_" . time() . ".pdf";
        
        // Generate PDF content
        $content = $pdf->output();
        
        // Store the PDF
        Storage::disk('local')->put($filename, $content);

        return $filename;
    }

    /**
     * Generate a PDF invoice for a booking
     */
    public function generateInvoice(Booking $booking): string
    {
        $booking->load(['student', 'instructor', 'surfSpot', 'payment']);

        $data = [
            'booking' => $booking,
            'invoice_number' => 'INV-' . str_pad($booking->id, 6, '0', STR_PAD_LEFT),
            'date_issued' => now()->format('F d, Y'),
            'due_date' => $booking->date->copy()->subDay()->format('F d, Y'),
            'company_name' => config('app.name', 'SiaSurf'),
            'company_address' => 'Siargao Island, Surigao del Norte, Philippines',
            'company_email' => config('app.admin_email', 'admin@siasurf.com'),
        ];

        $pdf = PDF::loadView('pdfs.invoice', $data);
        $pdf->setPaper('A4');
        
        $filename = "invoices/booking_{$booking->id}_" . time() . ".pdf";
        
        $content = $pdf->output();
        Storage::disk('local')->put($filename, $content);

        return $filename;
    }

    /**
     * Get the URL for a generated receipt
     */
    public function getReceiptUrl(string $filename): string
    {
        return Storage::disk('local')->url($filename);
    }

    /**
     * Delete a receipt file
     */
    public function deleteReceipt(string $filename): bool
    {
        if (Storage::disk('local')->exists($filename)) {
            return Storage::disk('local')->delete($filename);
        }
        return false;
    }

    /**
     * Stream a receipt for download
     */
    public function streamReceipt(Booking $booking)
    {
        $receiptPath = $this->generateReceipt($booking);
        
        return response()->download(
            Storage::disk('local')->path($receiptPath),
            "SiaSurf_Receipt_{$booking->id}.pdf"
        );
    }
}