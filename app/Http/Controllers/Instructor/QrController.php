<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\InstructorStatus;
use App\Http\Controllers\Controller;
use App\Services\InstructorVerificationService;
use App\Services\QRCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class QrController extends Controller
{
    /**
     * The instructor's own printable beach QR pass.
     */
    public function show(
        Request $request,
        QRCodeService $qrCodes,
        InstructorVerificationService $verification
    ): Response {
        $user = $request->user();
        $profile = $user->instructorProfile;

        abort_if($profile === null, 404);

        if ($profile->status === InstructorStatus::Active
            && (!$profile->qr_code_path || !Storage::disk('local')->exists($profile->qr_code_path))) {
            $qrCodes->generateInstructorQR($profile);
            $profile->refresh();
        }

        $summary = $verification->summarize($user);
        $qrReady = $profile->qr_code_path !== null
            && Storage::disk('local')->exists($profile->qr_code_path);

        return Inertia::render('Instructor/Qr/Show', [
            'instructor' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
            'profile' => [
                'status' => $profile->status->value,
                'status_label' => $profile->status->label(),
                'level' => $profile->level->value,
                'level_label' => $profile->level->shortLabel(),
                'rate_per_hour' => (float) $profile->rate_per_hour,
            ],
            'verification' => $summary,
            'qrReady' => $qrReady,
            'qrImageUrl' => $qrReady ? route('qr.image', $user->id) : null,
            'qrDownloadUrl' => $qrReady ? route('qr.download', $user->id) : null,
            'verificationUrl' => route('walkin.scan', $user->id),
        ]);
    }
}
