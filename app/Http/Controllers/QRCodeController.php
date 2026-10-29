<?php

namespace App\Http\Controllers;

use App\Enums\InstructorStatus;
use App\Models\User;
use App\Services\QRCodeService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class QRCodeController extends Controller
{
    /**
     * Serve the static instructor QR. The image is public because it only
     * encodes the verification URL; the page it opens performs the live check.
     */
    public function image(User $instructor)
    {
        $path = $this->resolvePath($instructor);

        abort_if($path === null, 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function download(User $instructor): BinaryFileResponse
    {
        $path = $this->resolvePath($instructor);

        abort_if($path === null, 404);

        return response()->download(
            Storage::disk('local')->path($path),
            "siasurf-instructor-{$instructor->id}-qr.svg",
            ['Content-Type' => 'image/svg+xml']
        );
    }

    private function resolvePath(User $instructor): ?string
    {
        abort_unless($instructor->isInstructor(), 404);

        $profile = $instructor->instructorProfile;

        if ($profile === null) {
            return null;
        }

        $path = $profile->qr_code_path;

        if ((!$path || !Storage::disk('local')->exists($path))
            && $profile->status === InstructorStatus::Active) {
            $path = app(QRCodeService::class)->generateInstructorQR($profile);
        }

        return $path && Storage::disk('local')->exists($path) ? $path : null;
    }
}
