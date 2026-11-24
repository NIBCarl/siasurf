<?php

namespace App\Services;

use App\Enums\WaiverType;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Waiver;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class WaiverService
{
    /**
     * Create a liability waiver for a booking (or specific participant).
     *
     * @param  array<string, mixed>  $details
     */
    public function createLiabilityWaiver(
        Booking $booking,
        string $signedBy,
        string $signatureData,
        ?BookingParticipant $participant = null,
        array $details = []
    ): Waiver {
        $booking->loadMissing(['student.studentProfile', 'instructor', 'surfSpot', 'primaryParticipant']);

        $resolvedParticipant = $participant ?? $booking->primaryParticipant;
        $isPrimary = $resolvedParticipant === null || $resolvedParticipant->isPrimary();
        $studentProfile = $booking->student?->studentProfile;

        $signedAt = now();
        $retentionUntil = $signedAt->copy()->addYears(7);

        $participantName = $details['participant_name']
            ?? ($isPrimary ? $booking->student?->name : $resolvedParticipant?->full_name)
            ?? $signedBy;

        $birthdayRaw = $details['birthday']
            ?? ($isPrimary
                ? $studentProfile?->birthday?->format('Y-m-d')
                : $resolvedParticipant?->birthday?->format('Y-m-d'));

        $birthdayCarbon = $birthdayRaw ? Carbon::parse($birthdayRaw) : null;

        $age = $details['age']
            ?? $birthdayCarbon?->age
            ?? $resolvedParticipant?->age
            ?? $booking->student_age;

        $email = $details['email']
            ?? ($isPrimary ? $booking->student?->email : $resolvedParticipant?->email);

        $country = $details['country']
            ?? ($isPrimary ? $studentProfile?->country : $resolvedParticipant?->country);

        $needsHotelPickup = (bool) $booking->needs_hotel_pickup;
        $hotelName = $needsHotelPickup
            ? ($details['hotel_name'] ?? $booking->hotel_name)
            : null;

        $emergencyContact = $details['emergency_contact'] ?? null;
        $medicalInfo = array_key_exists('medical_info', $details)
            ? $details['medical_info']
            : $resolvedParticipant?->medical_info;
        $surfShop = $details['surf_shop'] ?? config('waiver.surf_shop', config('app.name', 'SiaSurf'));
        $instructorName = $details['instructor_name'] ?? ($booking->instructor?->name ?? '');
        $witnessName = $details['witness_name'] ?? ($booking->instructor?->name ?? '');
        $signedByGuardian = (bool) ($details['signed_by_guardian'] ?? ($age !== null && $age < 18));

        $snapshot = [
            'participant_name' => $participantName,
            'birthday' => $birthdayCarbon?->format('Y-m-d'),
            'birthday_formatted' => $birthdayCarbon?->format('F d, Y'),
            'age' => $age !== null ? (int) $age : null,
            'email' => $email,
            'country' => $country,
            'needs_hotel_pickup' => $needsHotelPickup,
            'hotel_name' => $hotelName,
            'emergency_contact' => $emergencyContact,
            'medical_info' => $medicalInfo,
            'surf_shop' => $surfShop,
            'instructor_name' => $instructorName,
            'witness_name' => $witnessName,
            'signed_by_guardian' => $signedByGuardian,
            'signature_data' => $signatureData,
            'participant_id' => $resolvedParticipant?->id,
        ];

        // Generate PDF
        $pdfPath = $this->generateWaiverPDF($booking, WaiverType::Liability, $signedBy, $signedAt, $snapshot);

        return Waiver::create([
            'booking_id' => $booking->id,
            'participant_id' => $resolvedParticipant?->id,
            'type' => WaiverType::Liability->value,
            'participant_name' => $snapshot['participant_name'],
            'birthday' => $snapshot['birthday'],
            'age' => $snapshot['age'],
            'email' => $snapshot['email'],
            'country' => $snapshot['country'],
            'hotel_name' => $snapshot['hotel_name'],
            'emergency_contact' => $snapshot['emergency_contact'],
            'medical_info' => $snapshot['medical_info'],
            'surf_shop' => $snapshot['surf_shop'],
            'instructor_name' => $snapshot['instructor_name'],
            'witness_name' => $snapshot['witness_name'],
            'signed_by_guardian' => $snapshot['signed_by_guardian'],
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
        ?BookingParticipant $participant = null,
        ?string $guardianRelationship = null,
        ?string $guardianEmail = null
    ): Waiver {
        $booking->loadMissing(['student.studentProfile', 'instructor', 'surfSpot', 'primaryParticipant']);

        $resolvedParticipant = $participant ?? $booking->primaryParticipant;
        $signedAt = now();
        $retentionUntil = $signedAt->copy()->addYears(7);

        $minorName = $resolvedParticipant?->full_name
            ?? $booking->student?->name
            ?? 'Minor Participant';

        $minorAge = $resolvedParticipant?->birthday?->age
            ?? $resolvedParticipant?->age
            ?? $booking->student_age;

        $signatureImage = $this->validImageDataUri($parentSignature);

        // Generate PDF with additional parental info and embedded signature
        $pdfPath = $this->generateParentalConsentPDF(
            $booking,
            $parentName,
            $emergencyContact,
            $emergencyPhone,
            $medicalInfo,
            $signedAt,
            [
                'participant_id' => $resolvedParticipant?->id,
                'minor_name' => $minorName,
                'minor_age' => $minorAge !== null ? (int) $minorAge : null,
                'guardian_relationship' => $guardianRelationship,
                'guardian_email' => $guardianEmail,
                'signature_image' => $signatureImage,
            ]
        );

        return Waiver::create([
            'booking_id' => $booking->id,
            'participant_id' => $resolvedParticipant?->id,
            'type' => WaiverType::ParentalConsent->value,
            'participant_name' => $minorName,
            'age' => $minorAge !== null ? (int) $minorAge : null,
            'emergency_contact' => trim($emergencyContact . ' — ' . $emergencyPhone),
            'medical_info' => $medicalInfo,
            'signed_by_guardian' => true,
            'guardian_relationship' => $guardianRelationship,
            'guardian_email' => $guardianEmail,
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
     * Render liability waiver PDF bytes matching the SISA Surf Lesson Waiver form.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function renderLiabilityWaiverPdf(
        Booking $booking,
        array $snapshot = [],
        string $signedBy = '',
        ?Carbon $signedAt = null,
        bool $isPreview = false
    ): string {
        $booking->loadMissing(['student', 'instructor', 'surfSpot']);

        $tz = config('app.timezone', 'Asia/Manila');
        $resolvedSignedAt = ($signedAt instanceof Carbon ? $signedAt->copy() : ($signedAt ? Carbon::parse($signedAt) : now()))->timezone($tz);
        $signatureImage = $this->validImageDataUri($snapshot['signature_data'] ?? null);
        $logoDataUri = $this->logoDataUri();

        $data = [
            'booking' => $booking,
            'waiver_type' => WaiverType::Liability->label(),
            'waiver_title' => config('waiver.title', 'SURF LESSON WAIVER, LIABILITY, AND INDEMNITY AGREEMENT'),
            'clauses' => config('waiver.clauses', []),
            'logo_data_uri' => $logoDataUri,
            'participant_name' => $snapshot['participant_name'] ?? $booking->student?->name ?? $signedBy,
            'birthday_formatted' => $snapshot['birthday_formatted'] ?? '',
            'age' => $snapshot['age'] ?? $booking->student_age,
            'email' => $snapshot['email'] ?? $booking->student?->email ?? '',
            'country' => $snapshot['country'] ?? '',
            'needs_hotel_pickup' => (bool) ($snapshot['needs_hotel_pickup'] ?? $booking->needs_hotel_pickup),
            'hotel_name' => $snapshot['hotel_name'] ?? null,
            'emergency_contact' => $snapshot['emergency_contact'] ?? '',
            'medical_info' => $snapshot['medical_info'] ?? null,
            'surf_shop' => $snapshot['surf_shop'] ?? config('waiver.surf_shop', 'SiaSurf'),
            'instructor_name' => $snapshot['instructor_name'] ?? ($booking->instructor?->name ?? ''),
            'witness_name' => $snapshot['witness_name'] ?? ($booking->instructor?->name ?? ''),
            'signed_by' => $signedBy,
            'signed_by_guardian' => (bool) ($snapshot['signed_by_guardian'] ?? false),
            'signature_image' => $signatureImage,
            'signed_at' => $resolvedSignedAt->format('F d, Y'),
            'signed_at_datetime' => $resolvedSignedAt->format('F d, Y h:i A'),
            'retention_until' => $resolvedSignedAt->copy()->addYears(7)->format('F d, Y'),
            'company_name' => config('app.name', 'SiaSurf'),
            'waiver_id' => 'WVR-' . str_pad((string) $booking->id, 6, '0', STR_PAD_LEFT),
            'is_preview' => $isPreview,
            'lesson_date' => $snapshot['lesson_date'] ?? ($booking->date ? Carbon::parse($booking->date)->format('F d, Y') : ''),
        ];

        $pdf = Pdf::loadView('pdfs.waiver', $data);
        $pdf->setPaper('A4', 'portrait');

        return $pdf->output();
    }

    /**
     * Generate liability waiver PDF matching the SISA Surf Lesson Waiver form.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function generateWaiverPDF(
        Booking $booking,
        WaiverType $type,
        string $signedBy,
        $signedAt,
        array $snapshot = []
    ): string {
        $content = $this->renderLiabilityWaiverPdf(
            $booking,
            $snapshot,
            $signedBy,
            $signedAt instanceof Carbon ? $signedAt : Carbon::parse($signedAt),
            false
        );

        $participantKey = $snapshot['participant_id'] ?? 'primary';
        $uniqueSuffix = substr(md5((string) microtime(true) . mt_rand()), 0, 6);
        $filename = "waivers/liability_booking_{$booking->id}_p{$participantKey}_" . time() . "_{$uniqueSuffix}.pdf";

        Storage::disk('local')->put($filename, $content);

        return $filename;
    }

    /**
     * Return a DomPDF-safe image data URI only when the payload decodes to a
     * valid bitmap image (so synthetic test strings cannot break PDF rendering).
     */
    private function validImageDataUri(?string $dataUri): ?string
    {
        if (! is_string($dataUri) || ! preg_match('#^data:image/(png|jpe?g|gif);base64,(.+)$#si', $dataUri, $matches)) {
            return null;
        }

        $binary = base64_decode($matches[2], true);

        if ($binary === false || strlen($binary) < 16) {
            return null;
        }

        if (@getimagesizefromstring($binary) === false) {
            return null;
        }

        return $dataUri;
    }

    /**
     * Inline the configured waiver logo as a data URI for offline DomPDF rendering.
     */
    private function logoDataUri(): ?string
    {
        $relative = config('waiver.logo_path', 'images/logo.png');
        $fullPath = public_path($relative);

        if (! is_file($fullPath) || ! is_readable($fullPath)) {
            return null;
        }

        $binary = @file_get_contents($fullPath);
        if ($binary === false) {
            return null;
        }

        $info = @getimagesizefromstring($binary);
        $mime = is_array($info) && ! empty($info['mime']) ? $info['mime'] : 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode($binary);
    }

    /**
     * Generate parental consent PDF
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function generateParentalConsentPDF(
        Booking $booking,
        string $parentName,
        string $emergencyContact,
        string $emergencyPhone,
        ?string $medicalInfo,
        $signedAt,
        array $snapshot = []
    ): string {
        $booking->loadMissing(['student', 'instructor', 'surfSpot']);
        $phSignedAt = ($signedAt instanceof Carbon ? $signedAt->copy() : Carbon::parse($signedAt))->timezone(config('app.timezone', 'Asia/Manila'));
        $data = [
            'booking' => $booking,
            'minor_name' => $snapshot['minor_name'] ?? ($booking->student?->name ?? 'Minor Participant'),
            'minor_age' => $snapshot['minor_age'] ?? $booking->student_age,
            'parent_name' => $parentName,
            'guardian_relationship' => $snapshot['guardian_relationship'] ?? null,
            'guardian_email' => $snapshot['guardian_email'] ?? null,
            'signature_image' => $snapshot['signature_image'] ?? null,
            'emergency_contact' => $emergencyContact,
            'emergency_phone' => $emergencyPhone,
            'medical_info' => $medicalInfo,
            'signed_at' => $phSignedAt->format('F d, Y'),
            'signed_at_datetime' => $phSignedAt->format('F d, Y h:i A'),
            'company_name' => config('app.name', 'SiaSurf'),
            'logo_data_uri' => $this->logoDataUri(),
            'waiver_id' => 'WVR-PC-' . str_pad((string) $booking->id, 6, '0', STR_PAD_LEFT),
        ];

        $pdf = Pdf::loadView('pdfs.parental_consent', $data);
        $pdf->setPaper('A4');

        $participantKey = $snapshot['participant_id'] ?? 'primary';
        $uniqueSuffix = substr(md5((string) microtime(true) . mt_rand()), 0, 6);
        $filename = "waivers/parental_consent_booking_{$booking->id}_p{$participantKey}_" . time() . "_{$uniqueSuffix}.pdf";
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
