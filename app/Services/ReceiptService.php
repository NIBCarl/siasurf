<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Notifications\PaymentReceivedNotification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ReceiptService
{
    /**
     * Generate a PDF receipt for a booking
     */
    public function generateReceipt(Booking $booking): string
    {
        $booking->load(['student', 'instructor', 'surfSpot', 'payment']);

        $receiptNumber = 'RCP-' . str_pad($booking->id, 6, '0', STR_PAD_LEFT);
        $payment = $booking->payment;
        $totalDue = (float) $booking->total_amount;
        $paidAmount = (float) ($payment->amount ?? $booking->total_amount);
        $isUnderpaid = max(0, round($totalDue - $paidAmount, 2)) > 0
            || ($payment && $payment->status?->value === 'underpaid');

        $gatewayTxId = $payment?->transaction_id;
        $hasRealGatewayTx = !empty($gatewayTxId) && !str_starts_with((string) $gatewayTxId, 'mock_');
        $hasExternalRef = !empty($payment?->receipt_reference) || $hasRealGatewayTx;

        $pageHeight = 615;
        if ($booking->effectiveServiceFeeAmount() > 0) {
            $pageHeight += 42;
        }
        if ($isUnderpaid) {
            $pageHeight += 85;
        }
        if ($hasExternalRef) {
            $pageHeight += 25;
        }

        $data = [
            'booking' => $booking,
            'receipt_number' => $receiptNumber,
            'date_issued' => now()->format('F d, Y'),
            'company_name' => config('app.name', 'SiaSurf'),
            'company_address' => 'Siargao Island, Surigao del Norte, Philippines',
            'company_email' => config('app.admin_email', 'admin@siasurf.com'),
            'logo_data_uri' => $this->logoDataUri(),
            'page_height' => $pageHeight,
        ];

        $pdf = PDF::loadView('pdfs.receipt', $data);
        $pdf->setPaper([0, 0, 420, $pageHeight]);
        
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
            'logo_data_uri' => $this->logoDataUri(),
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
     * Canonical download/attachment filename for a booking receipt PDF.
     */
    public function receiptFileName(Booking $booking): string
    {
        return "SiaSurf_Receipt_{$booking->id}.pdf";
    }

    /**
     * Stream a receipt for download
     */
    public function streamReceipt(Booking $booking)
    {
        $receiptPath = $this->generateReceipt($booking);

        return response()->download(
            Storage::disk('local')->path($receiptPath),
            $this->receiptFileName($booking)
        );
    }

    /**
     * Generate the official receipt PDF once and send payment receipt
     * notifications (email with PDF + database bell) to both student and instructor.
     */
    public function sendPaymentReceipts(Booking $booking): void
    {
        $booking->loadMissing(['student', 'instructor', 'surfSpot', 'payment']);

        if (! $booking->payment || $booking->payment->status !== PaymentStatus::Completed) {
            return;
        }

        $receiptPath = null;

        try {
            $receiptPath = $this->generateReceipt($booking);
        } catch (\Throwable $e) {
            report($e);
        }

        $recipients = collect([$booking->student, $booking->instructor])
            ->filter()
            ->unique('id');

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new PaymentReceivedNotification($booking->payment, $receiptPath));
            } catch (\Throwable $e) {
                Log::warning('Failed to send payment receipt notification.', [
                    'booking_id' => $booking->id,
                    'recipient_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Inline the SiaSurf badge/logo as a data URI for offline DomPDF rendering.
     */
    private function logoDataUri(): ?string
    {
        foreach (['images/logo-badge.png', config('waiver.logo_path', 'images/logo.png')] as $relative) {
            $fullPath = public_path($relative);
            if (! is_file($fullPath) || ! is_readable($fullPath)) {
                continue;
            }

            $binary = @file_get_contents($fullPath);
            if ($binary === false) {
                continue;
            }

            $info = @getimagesizefromstring($binary);
            $mime = is_array($info) && ! empty($info['mime']) ? $info['mime'] : 'image/png';

            return 'data:' . $mime . ';base64,' . base64_encode($binary);
        }

        return null;
    }
}