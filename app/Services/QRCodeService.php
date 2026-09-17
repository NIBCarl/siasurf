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
     */
    public function generateInstructorQR(InstructorProfile $instructor): string
    {
        $qrData = route('walkin.scan', ['instructor' => $instructor->user_id]);

        $filename = "instructors/{$instructor->user_id}/qr-code.svg";

        $qrCode = QrCode::format('svg')
            ->size(400)
            ->margin(2)
            ->generate($qrData);

        Storage::disk('local')->put($filename, $qrCode);

        $instructor->update(['qr_code_path' => $filename]);

        return $filename;
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
