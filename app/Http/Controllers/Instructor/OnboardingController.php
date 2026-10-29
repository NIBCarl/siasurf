<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\InstructorStatus;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\FileScanService;
use App\Services\PricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        // PHP discards the entire request (POST + FILES) when it exceeds post_max_size.
        // Laravel then sees an empty request, so validation reports "certificates are
        // required" even though the user did attach files. Detect and explain it.
        if ($this->requestBodyWasDiscarded($request)) {
            return back()->withErrors([
                'certificates' => 'Your upload was larger than this server accepts (max '.$this->postMaxSizeLabel().' per submission). Please compress your files or upload them one at a time.',
            ]);
        }

        $validated = $request->validate([
            'bio' => 'required|string|max:1000',
            'level' => 'required|integer|in:1,2,3',
            // Bounded on both ends: a floor-only rule let absurd values reach
            // decimal(10,2) and fail as a raw SQL error.
            'rate_per_hour' => 'required|numeric|'.PricingService::rateRangeRule(),
            'certificates' => 'required|array|min:1',
            'certificates.*' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240', // Max 10MB
            'area' => 'nullable|string|max:100',
            'meeting_point' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:9.5,10.2',
            'longitude' => 'nullable|numeric|between:125.8,126.5',
        ], [
            'certificates.required' => 'Attach at least one certificate file (PDF, JPG, or PNG) before submitting.',
            'certificates.min' => 'Attach at least one certificate file (PDF, JPG, or PNG) before submitting.',
            'certificates.*.max' => 'Each certificate must be 10 MB or smaller. Compress or scan it at a lower quality and try again.',
            'certificates.*.mimes' => 'Certificates must be a PDF, JPG, or PNG file.',
            'certificates.*.uploaded' => 'A certificate could not be uploaded because it is larger than this server allows (max '.$this->uploadMaxSizeLabel().' per file). Compress it and try again.',
            'bio.max' => 'Keep your bio to 1000 characters or fewer.',
            'bio.required' => 'Tell students about your experience before submitting.',
            'rate_per_hour.min' => 'The base rate must be at least ₱'.number_format(PricingService::minRatePerHour()).' per hour.',
            'rate_per_hour.max' => 'The standard rate cannot exceed ₱'.number_format(PricingService::maxRatePerHour()).' per hour.',
            'rate_per_hour.numeric' => 'Enter the rate as a number, for example 800.',
        ]);

        $user = auth()->user();

        // Create or update the instructor profile
        $user->instructorProfile()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'bio' => $validated['bio'],
                'level' => $validated['level'],
                'rate_per_hour' => $validated['rate_per_hour'],
                'status' => InstructorStatus::PendingVerification,
                'area' => $validated['area'] ?? null,
                'meeting_point' => $validated['meeting_point'] ?? null,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
            ]
        );

        // Upload certificates
        if ($request->hasFile('certificates')) {
            $scanner = app(FileScanService::class);

            foreach ($request->file('certificates') as $file) {
                // Phase 13 - verify the real MIME type and (when enabled) scan
                // for malware before the file is written anywhere.
                try {
                    $scanner->assertSafe($file);
                } catch (\RuntimeException $exception) {
                    return back()->withErrors([
                        'certificates' => $exception->getMessage(),
                    ]);
                }

                // Try to determine certificate type based on filename, otherwise default to SurfSkill
                // NOTE: 'sisa' must be tested before 'isa' — "sisa" contains "isa".
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

        return redirect()->route('instructor.pending')->with('success', 'Profile and certificates submitted successfully. Please wait for Admin verification.');
    }

    /**
     * Did PHP throw away the whole request body because it exceeded post_max_size?
     * In that case $_POST and $_FILES are both empty while CONTENT_LENGTH is not,
     * which makes validation report misleading "required" errors.
     */
    private function requestBodyWasDiscarded(Request $request): bool
    {
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);

        return $request->isMethod('post')
            && $contentLength > 0
            && empty($_POST)
            && empty($_FILES)
            && $contentLength > $this->iniToBytes('post_max_size');
    }

    private function uploadMaxSizeLabel(): string
    {
        return $this->formatMb($this->iniToBytes('upload_max_filesize'));
    }

    private function postMaxSizeLabel(): string
    {
        return $this->formatMb($this->iniToBytes('post_max_size'));
    }

    private function formatMb(int $bytes): string
    {
        return $bytes > 0 ? round($bytes / 1048576).' MB' : 'the server limit';
    }

    /**
     * Convert a php.ini shorthand size (e.g. "12M") to bytes.
     */
    private function iniToBytes(string $key): int
    {
        $value = trim((string) ini_get($key));

        if ($value === '') {
            return 0;
        }

        $bytes = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $bytes * 1073741824,
            'm' => $bytes * 1048576,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }
}
