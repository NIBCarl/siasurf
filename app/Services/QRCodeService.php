<?php

namespace App\Services;

use App\Models\InstructorProfile;
use App\Models\StudentProfile;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class QRCodeService
{
    /**
     * Generate a scannable QR code for an instructor.
     * The QR encodes a URL pointing to the walk-in scan page.
     *
     * The payload uses the public app URL rather than `route()`, because
     * `route()` resolves against APP_URL — which is `http://localhost:8000`
     * in development. A phone scanning a QR built from that hits its OWN
     * localhost and fails. See config('siasurf.qr.public_url').
     */
    public function generateInstructorQR(InstructorProfile $instructor): string
    {
        $filename = "instructors/{$instructor->user_id}/qr-code.svg";

        $qrCode = QrCode::format('svg')
            ->size(400)
            ->margin(2)
            ->generate($this->scanUrl($instructor));

        Storage::disk('local')->put($filename, $qrCode);

        $instructor->update(['qr_code_path' => $filename]);

        return $filename;
    }

    /**
     * Ensure the instructor has a QR file on disk, generating it only when
     * missing. Verification used to be the ONLY caller of
     * generateInstructorQR(), so any instructor activated another way (seeder,
     * edit form, status change) ended up with `qr_code_path = null` and a 404
     * on `/qr/{id}/image`. This makes the operation idempotent and safe to call
     * from every activation path.
     */
    public function ensureInstructorQR(InstructorProfile $instructor): string
    {
        $path = $instructor->qr_code_path;

        if ($path && Storage::disk('local')->exists($path)) {
            return $path;
        }

        return $this->generateInstructorQR($instructor);
    }

    /**
     * The absolute URL an instructor QR should open.
     */
    public function scanUrl(InstructorProfile $instructor): string
    {
        $base = rtrim((string) config('siasurf.qr.public_url'), '/');

        if ($base === '') {
            return route('walkin.scan', ['instructor' => $instructor->user_id]);
        }

        return $base . '/qr/' . $instructor->user_id;
    }

    /**
     * Generate a QR code for a student (walk-in check-in).
     */
    public function generateStudentQR(StudentProfile $student): string
    {
        $qrData = json_encode([
            'v' => '1.0',
            'type' => 'siasurf_student',
            'id' => $student->user_id,
            'name' => $student->user->name,
            'token' => $student->walkin_token,
        ]);

        $filename = "students/{$student->user_id}/qr-code.svg";

        $qrCode = QrCode::format('svg')
            ->size(400)
            ->margin(2)
            ->generate($qrData);

        Storage::disk('local')->put($filename, $qrCode);

        return $filename;
    }

    /**
     * Get the URL for an instructor's QR code.
     */
    public function getQRCodeUrl(InstructorProfile $instructor): ?string
    {
        if (!$instructor->qr_code_path) {
            return null;
        }

        return Storage::disk('local')->url($instructor->qr_code_path);
    }

    /**
     * Delete an instructor's QR code.
     */
    public function deleteQRCode(InstructorProfile $instructor): void
    {
        if ($instructor->qr_code_path && Storage::disk('local')->exists($instructor->qr_code_path)) {
            Storage::disk('local')->delete($instructor->qr_code_path);
        }
    }
}
