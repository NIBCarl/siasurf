<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Waiver;
use App\Enums\WaiverType;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class WaiverService
{
    /**
     * Create a liability waiver for a booking
     */
    public function createLiabilityWaiver(
        Booking $booking,
        string $signedBy,
        string $signatureData,
        ?BookingParticipant $participant = null
    ): Waiver {
        $signedAt = now();
        $retentionUntil = $signedAt->copy()->addYears(7);

        // Generate PDF
        $pdfPath = $this->generateWaiverPDF($booking, WaiverType::Liability, $signedBy, $signedAt);

        return Waiver::create([
            'booking_id' => $booking->id,
            'participant_id' => $participant?->id ?? $booking->primaryParticipant?->id,
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
        ?string $medicalInfo = null,
        ?BookingParticipant $participant = null
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
            'participant_id' => $participant?->id ?? $booking->primaryParticipant?->id,
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
        $participants = $booking->participants()->get();

        if ($participants->isNotEmpty()) {
            return $participants->every(fn (BookingParticipant $participant) => $this->participantCovered($participant));
        }

        // Legacy booking with no participant rows: booking-level check.
        $hasLiability = $booking->waivers()
            ->where('type', WaiverType::Liability->value)
            ->exists();

        if (! $hasLiability) {
            return false;
        }

        if ($booking->student_age < 18) {
            return $booking->waivers()
                ->where('type', WaiverType::ParentalConsent->value)
                ->exists();
        }

        return true;
    }

    /**
     * Get missing waiver types for a booking.
     */
    public function getMissingWaiverTypes(Booking $booking): array
    {
        $participants = $booking->participants()->get();

        if ($participants->isNotEmpty()) {
            $missing = [];

            if ($participants->contains(fn (BookingParticipant $p) => ! $p->hasSigned(WaiverType::Liability))) {
                $missing[] = WaiverType::Liability->value;
            }

            if ($participants->contains(fn (BookingParticipant $p) => $p->isMinor() && ! $p->hasSigned(WaiverType::ParentalConsent))) {
                $missing[] = WaiverType::ParentalConsent->value;
            }

            return $missing;
        }

        $missing = [];

        if (! $booking->waivers()->where('type', WaiverType::Liability->value)->exists()) {
            $missing[] = WaiverType::Liability->value;
        }

        if ($booking->student_age < 18
            && ! $booking->waivers()->where('type', WaiverType::ParentalConsent->value)->exists()) {
            $missing[] = WaiverType::ParentalConsent->value;
        }

        return $missing;
    }

    /**
     * First participant who still needs to sign the liability waiver.
     */
    public function nextParticipantAwaitingLiability(Booking $booking): ?BookingParticipant
    {
        return $booking->participants()
            ->get()
            ->first(fn (BookingParticipant $p) => ! $p->hasSigned(WaiverType::Liability));
    }

    /**
     * First minor who still needs parental consent.
     */
    public function nextParticipantAwaitingParentalConsent(Booking $booking): ?BookingParticipant
    {
        return $booking->participants()
            ->get()
            ->first(fn (BookingParticipant $p) => $p->isMinor() && ! $p->hasSigned(WaiverType::ParentalConsent));
    }

    public function participantCovered(BookingParticipant $participant): bool
    {
        if (! $participant->hasSigned(WaiverType::Liability)) {
            return false;
        }

        return ! $participant->isMinor() || $participant->hasSigned(WaiverType::ParentalConsent);
    }

    /**
     * Signing progress for the guided waiver flow.
     *
     * @return array{participants: int, signed: int, required: int}
     */
    public function waiverProgress(Booking $booking): array
    {
        $participants = $booking->participants()->get();

        $required = 0;
        $signed = 0;

        foreach ($participants as $participant) {
            $required++; // liability
            if ($participant->hasSigned(WaiverType::Liability)) {
                $signed++;
            }

            if ($participant->isMinor()) {
                $required++; // parental consent
                if ($participant->hasSigned(WaiverType::ParentalConsent)) {
                    $signed++;
                }
            }
        }

        return [
            'participants' => $participants->count(),
            'signed' => $signed,
            'required' => $required,
        ];
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