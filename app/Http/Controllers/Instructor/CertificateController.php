<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\User;
use App\Notifications\CertificateSubmittedNotification;
use App\Services\FileScanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CertificateController extends Controller
{
    public function store(Request $request, FileScanService $scanner): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasInstructorProfile()) {
            return redirect()->route('instructor.onboarding')
                ->with('error', 'Complete your instructor onboarding first.');
        }

        $request->validate([
            'certificates' => 'required|array|min:1',
            'certificates.*' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240', // Max 10MB
        ], [
            'certificates.required' => 'Choose at least one file (PDF, JPG, or PNG) to upload.',
            'certificates.min' => 'Choose at least one file (PDF, JPG, or PNG) to upload.',
            'certificates.*.max' => 'Each document must be 10 MB or smaller.',
            'certificates.*.mimes' => 'Documents must be a PDF, JPG, or PNG file.',
            'certificates.*.uploaded' => 'A document could not be uploaded because it is larger than this server allows (max '.$this->uploadMaxSizeLabel().' per file). Compress it and try again.',
        ]);

        $uploadedCount = 0;

        if ($request->hasFile('certificates')) {
            foreach ($request->file('certificates') as $file) {
                // Phase 13: verify the real MIME type and (when enabled) scan for
                // malware before the file is written, matching onboarding.
                try {
                    $scanner->assertSafe($file);
                } catch (\RuntimeException $exception) {
                    return back()->withErrors([
                        'certificates' => $exception->getMessage(),
                    ]);
                }

                $type = CertificateType::fromFilename($file->getClientOriginalName());

                // Store file on private disk based on environment configuration
                $disk = config('filesystems.default') === 's3' ? 's3' : 'local';
                $path = $file->store('certificates', $disk);

                $user->certificates()->create([
                    'type' => $type,
                    'file_path' => $path,
                    'status' => CertificateStatus::PendingVerification,
                ]);

                $uploadedCount++;
            }
        }

        if ($uploadedCount > 0) {
            User::query()->admins()->get()->each(function (User $admin) use ($user, $uploadedCount): void {
                try {
                    $admin->notify(new CertificateSubmittedNotification($user, $uploadedCount));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to notify admin of uploaded instructor certificates.', [
                        'instructor_id' => $user->id,
                        'admin_id' => $admin->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
        }

        return back()->with('success', 'Certificates uploaded successfully and are pending verification.');
    }

    private function uploadMaxSizeLabel(): string
    {
        $value = trim((string) ini_get('upload_max_filesize'));

        if ($value === '') {
            return 'the server limit';
        }

        $bytes = (int) $value;
        $bytes = match (strtolower(substr($value, -1))) {
            'g' => $bytes * 1073741824,
            'm' => $bytes * 1048576,
            'k' => $bytes * 1024,
            default => $bytes,
        };

        return $bytes > 0 ? round($bytes / 1048576).' MB' : 'the server limit';
    }
}
