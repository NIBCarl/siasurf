<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Certificate;
use App\Enums\CertificateStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\RedirectResponse;

class CertificateController extends Controller
{
    /**
     * Store a newly uploaded certificate.
     */
    public function store(Request $request, User $instructor): RedirectResponse
    {
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

        // Generate unique filename
        $filename = $validated['type'] . '_' . time() . '_' . Str::random(8) . '.pdf';
        
        // Store file
        $path = $file->storeAs(
            "instructors/{$instructor->id}/certificates",
            $filename,
            'local'
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
        
        if ($user->id !== $certificate->user_id && !$user->isAdmin()) {
            abort(403, 'Unauthorized access to certificate.');
        }

        $disk = config('filesystems.default') === 's3' ? 's3' : 'public';
        
        if (!Storage::disk($disk)->exists($certificate->file_path)) {
            abort(404, 'Certificate file not found.');
        }

        return Storage::disk($disk)->response($certificate->file_path);
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

        return back()->with('success', 'Certificate rejected.');
    }

    /**
     * Remove the specified certificate.
     */
    public function destroy(Certificate $certificate): RedirectResponse
    {
        $this->authorize('delete', $certificate);

        // Delete file from storage
        if (Storage::disk('local')->exists($certificate->file_path)) {
            Storage::disk('local')->delete($certificate->file_path);
        }

        $certificate->delete();

        return back()->with('success', 'Certificate deleted successfully.');
    }
}