<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CertificateStatus;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\User;
use App\Notifications\CertificateStatusNotification;
use App\Services\FileScanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CertificateController extends Controller
{
    /**
     * Store a newly uploaded certificate.
     */
    public function store(Request $request, User $instructor): RedirectResponse
    {
        abort_unless($instructor->isInstructor(), 404);

        $validated = $request->validate([
            'certificate' => [
                'required',
                'file',
                'mimes:pdf',
                'max:10240', // 10MB max
            ],
            'type' => 'required|in:bls,wasar,surf_skill,isa,sisa',
        ]);

        $file = $request->file('certificate');

        try {
            app(FileScanService::class)->assertSafe($file);
        } catch (\RuntimeException $exception) {
            return back()->withErrors([
                'certificate' => $exception->getMessage(),
            ]);
        }

        // Generate unique filename
        $filename = $validated['type'].'_'.time().'_'.Str::random(8).'.pdf';

        $disk = config('filesystems.default') === 's3' ? 's3' : 'local';

        // Store file
        $path = $file->storeAs(
            "instructors/{$instructor->id}/certificates",
            $filename,
            $disk
        );

        // Create certificate record
        Certificate::create([
            'user_id' => $instructor->id,
            'type' => $validated['type'],
            'file_path' => $path,
            'status' => CertificateStatus::PendingVerification,
        ]);

        return back()->with('success', 'Certificate uploaded successfully. Pending verification.');
    }

    /**
     * Display the specified certificate.
     */
    public function show(Certificate $certificate)
    {
        // Check if user can view this certificate
        $user = auth()->user();

        if ($user->id !== $certificate->user_id && ! $user->isAdmin()) {
            abort(403, 'Unauthorized access to certificate.');
        }

        $disk = $this->resolveDisk($certificate->file_path);

        if ($disk === null) {
            abort(404, 'Certificate file not found.');
        }

        return Storage::disk($disk)->response($certificate->file_path);
    }

    /**
     * Admin-uploaded certificates live on the private disk while instructor
     * self-service uploads use the public disk. Resolve whichever disk actually
     * holds the file instead of assuming one and returning a false 404.
     */
    private function resolveDisk(string $path): ?string
    {
        $candidates = config('filesystems.default') === 's3'
            ? ['s3']
            : ['local', 'public'];

        foreach ($candidates as $disk) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    return $disk;
                }
            } catch (\Throwable) {
                // A disk that is not configured must not break the lookup.
            }
        }

        return null;
    }

    /**
     * Verify a certificate.
     */
    public function verify(Request $request, Certificate $certificate): RedirectResponse
    {
        $this->authorize('verify', $certificate);

        $validated = $request->validate([
            'expires_at' => 'nullable|date|after:today',
        ]);

        $certificate->update([
            'status' => CertificateStatus::Verified,
            'verified_at' => now(),
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        try {
            $certificate->user?->notify(new CertificateStatusNotification($certificate->fresh(), verified: true));
        } catch (\Throwable $exception) {
            Log::warning('Failed to send certificate verified notification.', [
                'certificate_id' => $certificate->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Certificate verified successfully.');
    }

    /**
     * Reject a certificate.
     */
    public function reject(Request $request, Certificate $certificate): RedirectResponse
    {
        $this->authorize('verify', $certificate);

        $validated = $request->validate([
            'notes' => 'required|string',
        ]);

        $certificate->update([
            'status' => CertificateStatus::Rejected,
            'admin_notes' => $validated['notes'],
        ]);

        try {
            $certificate->user?->notify(new CertificateStatusNotification(
                $certificate->fresh(),
                verified: false,
                notes: $validated['notes']
            ));
        } catch (\Throwable $exception) {
            Log::warning('Failed to send certificate rejected notification.', [
                'certificate_id' => $certificate->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Certificate rejected.');
    }

    /**
     * Remove the specified certificate.
     */
    public function destroy(Certificate $certificate): RedirectResponse
    {
        $this->authorize('delete', $certificate);

        foreach (['local', 'public'] as $disk) {
            try {
                if (Storage::disk($disk)->exists($certificate->file_path)) {
                    Storage::disk($disk)->delete($certificate->file_path);
                }
            } catch (\Throwable) {
                // Ignore unconfigured storage disks during cleanup.
            }
        }

        $certificate->delete();

        return back()->with('success', 'Certificate deleted successfully.');
    }
}
