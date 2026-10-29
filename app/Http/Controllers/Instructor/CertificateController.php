<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\FileScanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    public function store(Request $request, FileScanService $scanner): RedirectResponse
    {
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

        $user = auth()->user();

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

                // Determine certificate type ('sisa' before 'isa' — "sisa" contains "isa")
                $type = CertificateType::SurfSkill;
                $filename = strtolower($file->getClientOriginalName());
                if (str_contains($filename, 'bls')) {
                    $type = CertificateType::BLS;
                } elseif (str_contains($filename, 'wasar')) {
                    $type = CertificateType::WaSAR;
                } elseif (str_contains($filename, 'sisa')) {
                    $type = CertificateType::SISA;
                } elseif (str_contains($filename, 'isa')) {
                    $type = CertificateType::ISA;
                } elseif (str_contains($filename, 'surf')) {
                    $type = CertificateType::SurfSkill;
                }

                // Store file based on environment configuration
                $disk = config('filesystems.default') === 's3' ? 's3' : 'public';
                $path = $file->store('certificates', $disk);

                $user->certificates()->create([
                    'type' => $type,
                    'file_path' => $path,
                    'status' => CertificateStatus::PendingVerification,
                ]);
            }
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
