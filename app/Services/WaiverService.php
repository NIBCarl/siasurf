<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Waiver;
use App\Enums\WaiverType;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class WaiverService
{
    /**
     * Create a liability waiver for a booking
     */
    public function createLiabilityWaiver(Booking $booking, string $signedBy, string $signatureData): Waiver
    {
        $signedAt = now();
        $retentionUntil = $signedAt->copy()->addYears(7);

        // Generate PDF
        $pdfPath = $this->generateWaiverPDF($booking, WaiverType::Liability, $signedBy, $signedAt);

        return Waiver::create([
            'booking_id' => $booking->id,
            'type' => WaiverType::Liability->value,
            'signed_by' => $signedBy,
            'signature_data' => $signatureData,
            'pdf_path' => $pdfPath,
            'signed_at' => $signedAt,
            'retention_until' => $retentionUntil,
        ]);
    }

    /**
     * Create a parental consent waiver for minors
     */
    public function createParentalConsent(
        Booking $booking,
        string $parentName,
        string $parentSignature,
        string $emergencyContact,
        string $emergencyPhone,
        ?string $medicalInfo = null
    ): Waiver {
        $signedAt = now();
        $retentionUntil = $signedAt->copy()->addYears(7);

        // Generate PDF with additional parental info
        $pdfPath = $this->generateParentalConsentPDF(
            $booking,
            $parentName,
            $emergencyContact,
            $emergencyPhone,
            $medicalInfo,
            $signedAt
        );

        return Waiver::create([
            'booking_id' => $booking->id,
            'type' => WaiverType::ParentalConsent->value,
            'signed_by' => $parentName,
            'signature_data' => $parentSignature,
            'pdf_path' => $pdfPath,
            'signed_at' => $signedAt,
            'retention_until' => $retentionUntil,
        ]);
    }

    /**
     * Check if booking has required waivers signed
     */
    public function hasRequiredWaivers(Booking $booking): bool
    {
        // Always need liability waiver
        $hasLiability = $booking->waiver()
            ->where('type', WaiverType::Liability->value)
            ->exists();

        if (!$hasLiability) {
            return false;
        }

        // If student is under 18, also need parental consent
        if ($booking->student_age < 18) {
            $hasParentalConsent = $booking->waiver()
                ->where('type', WaiverType::ParentalConsent->value)
                ->exists();

            return $hasParentalConsent;
        }

        return true;
    }

    /**
     * Get missing waiver types for a booking
     */
    public function getMissingWaiverTypes(Booking $booking): array
    {
        $missing = [];

        // Check liability waiver
        $hasLiability = $booking->waiver()
            ->where('type', WaiverType::Liability->value)
            ->exists();

        if (!$hasLiability) {
            $missing[] = WaiverType::Liability->value;
        }

        // Check parental consent for minors
        if ($booking->student_age < 18) {
            $hasParentalConsent = $booking->waiver()
                ->where('type', WaiverType::ParentalConsent->value)
                ->exists();

            if (!$hasParentalConsent) {
                $missing[] = WaiverType::ParentalConsent->value;
            }
        }

        return $missing;
    }

    /**
     * Generate liability waiver PDF
     */
    private function generateWaiverPDF(Booking $booking, WaiverType $type, string $signedBy, $signedAt): string
    {
        $data = [
            'booking' => $booking,
            'waiver_type' => $type->label(),
            'signed_by' => $signedBy,
            'signed_at' => $signedAt->format('F d, Y'),
            'signed_at_datetime' => $signedAt->format('F d, Y h:i A'),
            'company_name' => config('app.name', 'SiaSurf'),
            'waiver_id' => 'WVR-' . str_pad($booking->id, 6, '0', STR_PAD_LEFT),
        ];

        $pdf = PDF::loadView('pdfs.waiver', $data);
        $pdf->setPaper('A4');

        $filename = "waivers/liability_booking_{$booking->id}_" . time() . ".pdf";
        $content = $pdf->output();

        Storage::disk('local')->put($filename, $content);

        return $filename;
    }

    /**
     * Generate parental consent PDF
     */
    private function generateParentalConsentPDF(
        Booking $booking,
        string $parentName,
        string $emergencyContact,
        string $emergencyPhone,
        ?string $medicalInfo,
        $signedAt
    ): string {
        $data = [
            'booking' => $booking,
            'parent_name' => $parentName,
            'emergency_contact' => $emergencyContact,
            'emergency_phone' => $emergencyPhone,
            'medical_info' => $medicalInfo,
            'signed_at' => $signedAt->format('F d, Y'),
            'signed_at_datetime' => $signedAt->format('F d, Y h:i A'),
            'company_name' => config('app.name', 'SiaSurf'),
            'waiver_id' => 'WVR-PC-' . str_pad($booking->id, 6, '0', STR_PAD_LEFT),
        ];

        $pdf = PDF::loadView('pdfs.parental_consent', $data);
        $pdf->setPaper('A4');

        $filename = "waivers/parental_consent_booking_{$booking->id}_" . time() . ".pdf";
        $content = $pdf->output();

        Storage::disk('local')->put($filename, $content);

        return $filename;
    }

    /**
     * Get waiver PDF URL
     */
    public function getWaiverUrl(Waiver $waiver): string
    {
        return Storage::disk('local')->url($waiver->pdf_path);
    }

    /**
     * Stream waiver for download
     */
    public function downloadWaiver(Waiver $waiver)
    {
        return response()->download(
            Storage::disk('local')->path($waiver->pdf_path),
            "SiaSurf_Waiver_{$waiver->booking_id}.pdf"
        );
    }

    /**
     * Delete waiver files that have exceeded retention period
     */
    public function cleanupExpiredWaivers(): int
    {
        $expiredWaivers = Waiver::where('retention_until', '<', now())->get();
        $count = 0;

        foreach ($expiredWaivers as $waiver) {
            // Delete file
            if (Storage::disk('local')->exists($waiver->pdf_path)) {
                Storage::disk('local')->delete($waiver->pdf_path);
            }

            // Delete record
            $waiver->delete();
            $count++;
        }

        return $count;
    }
}