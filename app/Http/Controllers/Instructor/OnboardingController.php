<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\InstructorStatus;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\SurfSpot;
use App\Models\User;
use App\Notifications\InstructorPendingVerificationNotification;
use App\Services\FileScanService;
use App\Services\PricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OnboardingController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->needsInstructorOnboarding()) {
            return redirect()->route($user->isVerifiedInstructor() ? 'instructor.dashboard' : 'instructor.pending');
        }

        // PHP discards the entire request (POST + FILES) when it exceeds post_max_size.
        // Laravel then sees an empty request, so validation reports "certificates are
        // required" even though the user did attach files. Detect and explain it.
        if ($this->requestBodyWasDiscarded($request)) {
            return back()->withErrors([
                'certificates' => 'Your upload was larger than this server accepts (max '.$this->postMaxSizeLabel().' per submission). Please compress your files or upload them one at a time.',
            ]);
        }

        $level = (int) $request->input('level', 1);
        $hasOfferedServices = $request->has('offered_services');

        $validated = $request->validate([
            'bio' => 'required|string|max:1000',
            'birthday' => ['nullable', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString()],
            'level' => 'required|integer|in:1,2,3',
            ...PricingService::offeringValidationRules($level, $request->input('offered_services'), $hasOfferedServices),
            ...SurfSpot::instructorSelectionValidationRules($level, requireWhenPresent: false),
            'certificates' => 'required|array|min:1',
            'certificates.*' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240', // Max 10MB
            'area' => 'nullable|string|max:100',
            'meeting_point' => 'nullable|string|max:255',
        ], [
            'certificates.required' => 'Attach at least one certificate file (PDF, JPG, or PNG) before submitting.',
            'certificates.min' => 'Attach at least one certificate file (PDF, JPG, or PNG) before submitting.',
            'certificates.*.max' => 'Each certificate must be 10 MB or smaller. Compress or scan it at a lower quality and try again.',
            'certificates.*.mimes' => 'Certificates must be a PDF, JPG, or PNG file.',
            'certificates.*.uploaded' => 'A certificate could not be uploaded because it is larger than this server allows (max '.$this->uploadMaxSizeLabel().' per file). Compress it and try again.',
            'bio.max' => 'Keep your bio to 1000 characters or fewer.',
            'bio.required' => 'Tell students about your experience before submitting.',
            'birthday.before_or_equal' => 'Instructors must be at least 18 years old.',
            ...PricingService::offeringValidationMessages($level),
        ]);

        $offerings = PricingService::normalizeOfferingsForLevel(
            (int) $validated['level'],
            $validated,
            $hasOfferedServices,
            $user->instructorProfile
        );

        // Phase 13 - verify the real MIME type and (when enabled) scan every
        // file before writing a profile or storing anything to disk.
        $files = $request->file('certificates', []);
        $scanner = app(FileScanService::class);

        foreach ($files as $file) {
            try {
                $scanner->assertSafe($file);
            } catch (\RuntimeException $exception) {
                return back()->withErrors([
                    'certificates' => $exception->getMessage(),
                ])->withInput($request->except('certificates'));
            }
        }

        $disk = config('filesystems.default') === 's3' ? 's3' : 'local';
        $storedCertificates = [];
        $isResubmission = $user->instructorProfile?->status === InstructorStatus::Rejected;

        try {
            foreach ($files as $file) {
                $storedCertificates[] = [
                    'type' => CertificateType::fromFilename($file->getClientOriginalName()),
                    'file_path' => $file->store('certificates', $disk),
                ];
            }

            DB::transaction(function () use ($user, $validated, $offerings, $storedCertificates): void {
                $profile = $user->instructorProfile()->updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'bio' => $validated['bio'],
                        'birthday' => $validated['birthday'] ?? $user->instructorProfile?->birthday,
                        'level' => $validated['level'],
                        ...$offerings,
                        'status' => InstructorStatus::PendingVerification,
                        'rejection_reason' => null,
                        'rejected_at' => null,
                        'area' => $validated['area'] ?? null,
                        'meeting_point' => $validated['meeting_point'] ?? null,
                    ]
                );

                if (array_key_exists('surf_spot_ids', $validated)) {
                    $profile->syncSurfSpotsAndLocation(
                        $validated['surf_spot_ids'] ?? [],
                        syncCoordinates: false,
                        submittedArea: $validated['area'] ?? null,
                    );
                } else {
                    $profile->pruneForCurrentLevel();
                }

                foreach ($storedCertificates as $certificate) {
                    $user->certificates()->create([
                        'type' => $certificate['type'],
                        'file_path' => $certificate['file_path'],
                        'status' => CertificateStatus::PendingVerification,
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            foreach ($storedCertificates as $certificate) {
                Storage::disk($disk)->delete($certificate['file_path']);
            }

            throw $exception;
        }

        $levelValue = (int) $validated['level'];
        $certificateCount = count($storedCertificates);

        User::query()->admins()->get()->each(function (User $admin) use ($user, $levelValue, $certificateCount, $isResubmission): void {
            try {
                $admin->notify(new InstructorPendingVerificationNotification(
                    $user,
                    $levelValue,
                    $certificateCount,
                    $isResubmission
                ));
            } catch (\Throwable $exception) {
                Log::warning('Failed to notify admin of pending instructor verification.', [
                    'instructor_id' => $user->id,
                    'admin_id' => $admin->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });

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
