<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CertificateStatus;
use App\Enums\InstructorStatus;
use App\Enums\SuspensionStatus;
use App\Enums\SuspensionType;
use App\Events\InstructorStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\InstructorProfile;
use App\Models\InstructorStatusLog;
use App\Models\InstructorSuspension;
use App\Models\SurfSpot;
use App\Models\User;
use App\Notifications\InstructorReactivated;
use App\Notifications\InstructorRejected;
use App\Notifications\InstructorVerified;
use App\Services\PricingService;
use App\Services\QRCodeService;
use App\Services\SuspensionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class InstructorController extends Controller
{
    protected $qrCodeService;

    public function __construct(QRCodeService $qrCodeService)
    {
        $this->qrCodeService = $qrCodeService;
    }

    /**
     * Display a listing of instructors.
     */
    public function index(Request $request): Response
    {
        $query = User::with('instructorProfile')
            ->where('role', 'instructor')
            ->whereHas('instructorProfile');

        // Filter by status
        if ($request->has('status')) {
            $query->whereHas('instructorProfile', function ($q) use ($request) {
                $q->where('status', $request->status);
            });
        }

        // Filter by level
        if ($request->has('level')) {
            $query->whereHas('instructorProfile', function ($q) use ($request) {
                $q->where('level', $request->level);
            });
        }

        // Search by name or email
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $instructors = $query->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Instructors/Index', [
            'instructors' => $instructors,
            'filters' => $request->only(['status', 'level', 'search']),
        ]);
    }

    /**
     * Show the form for creating a new instructor.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Instructors/Create', [
            'availableSurfSpots' => SurfSpot::query()
                ->active()
                ->orderByRaw("CASE difficulty WHEN 'beginner' THEN 1 WHEN 'intermediate' THEN 2 ELSE 3 END")
                ->orderBy('name')
                ->get()
                ->map(fn (SurfSpot $spot): array => $spot->toMapPayload())
                ->values()
                ->all(),
        ]);
    }

    /**
     * Store a newly created instructor.
     */
    public function store(Request $request): RedirectResponse
    {
        $level = (int) $request->input('level', 1);
        $hasOfferedServices = $request->has('offered_services');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
            'bio' => 'nullable|string',
            'level' => 'required|integer|in:1,2,3',
            ...PricingService::offeringValidationRules($level, $request->input('offered_services'), $hasOfferedServices),
            ...SurfSpot::instructorSelectionValidationRules($level, requireWhenPresent: false),
            'area' => 'nullable|string|max:100',
            'meeting_point' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:9.5,10.2',
            'longitude' => 'nullable|numeric|between:125.8,126.5',
        ], PricingService::offeringValidationMessages($level));

        // Create user
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'instructor',
            'phone' => $validated['phone'] ?? null,
        ]);

        $offerings = PricingService::normalizeOfferingsForLevel((int) $validated['level'], $validated, $hasOfferedServices);

        // Create instructor profile
        $profile = InstructorProfile::create([
            'user_id' => $user->id,
            'bio' => $validated['bio'] ?? null,
            'level' => $validated['level'],
            'status' => InstructorStatus::PendingVerification->value,
            ...$offerings,
            'area' => $validated['area'] ?? null,
            'meeting_point' => $validated['meeting_point'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
        ]);

        if (array_key_exists('surf_spot_ids', $validated)) {
            $profile->syncSurfSpotsAndLocation(
                $validated['surf_spot_ids'] ?? [],
                syncCoordinates: ! $request->filled('latitude') && ! $request->filled('longitude'),
                submittedArea: $validated['area'] ?? null,
            );
        }

        // Assign role
        $user->assignRole('instructor');

        return redirect()
            ->route('admin.instructors.index')
            ->with('success', 'Instructor registered successfully. Pending verification.');
    }

    /**
     * Display the specified instructor.
     */
    public function show(User $instructor): Response
    {
        $instructor->load(['instructorProfile.surfSpots', 'certificates', 'availabilities']);

        // Existing active instructors verified before the QR module existed may
        // not have an image on disk yet. Generate it lazily so the dossier is
        // always actionable.
        $profile = $instructor->instructorProfile;
        if ($profile && $profile->status === InstructorStatus::Active) {
            $path = $profile->qr_code_path;
            if (! $path || ! Storage::disk('local')->exists($path)) {
                $this->qrCodeService->ensureInstructorQR($profile);
                $instructor->load('instructorProfile.surfSpots');
                $profile = $instructor->instructorProfile;
            }
        }

        return Inertia::render('Admin/Instructors/Show', [
            'instructor' => $instructor,
            'serviceRates' => $profile?->serviceRates() ?? [],
            'surfSpots' => $profile?->activeAllowedSurfSpots()
                ->map(fn (SurfSpot $spot): array => $spot->toMapPayload())
                ->values()
                ->all() ?? [],
            'qrImageUrl' => $instructor->instructorProfile?->qr_code_path
                ? route('qr.image', $instructor->id)
                : null,
            'verificationUrl' => route('walkin.scan', $instructor->id),
        ]);
    }

    /**
     * Verify an instructor.
     */
    public function verify(Request $request, User $instructor): RedirectResponse
    {
        $this->authorize('verify', $instructor->instructorProfile);

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $profile = $instructor->instructorProfile;

        if (! $profile) {
            return back()->with('error', 'Instructor profile not found.');
        }

        $oldStatus = $profile->status;

        // Verify explicitly selected certificates (scoped to this instructor).
        if ($request->filled('certificate_ids') && is_array($request->certificate_ids)) {
            $instructor->certificates()
                ->whereIn('id', $request->certificate_ids)
                ->update([
                    'status' => CertificateStatus::Verified->value,
                    'verified_at' => now(),
                ]);
        } elseif (! $instructor->hasVerifiedCertificate()) {
            // If the admin clicked Verify & License without ticking individual
            // boxes, approve the instructor's pending certificates so they are
            // immediately discoverable in Find Instructor (BR-2.1).
            $instructor->certificates()
                ->where('status', CertificateStatus::PendingVerification->value)
                ->update([
                    'status' => CertificateStatus::Verified->value,
                    'verified_at' => now(),
                ]);
        }

        // Final safety check: Does the instructor have ANY verified certificates now?
        if (! $instructor->hasVerifiedCertificate()) {
            return back()->with('error', 'Instructor must have at least one verified certificate before they can be licensed.');
        }

        // Generate QR code
        $qrPath = $this->qrCodeService->ensureInstructorQR($profile);

        // Update profile
        $profile->update([
            'status' => InstructorStatus::Active->value,
            'qr_code_path' => $qrPath,
        ]);

        // Send notification to instructor
        try {
            $instructor->notify(new InstructorVerified($profile->fresh()));
        } catch (\Exception $e) {
            \Log::error('Failed to send verification notification: '.$e->getMessage());
        }

        $this->broadcastStatusChange($instructor, $oldStatus, InstructorStatus::Active, 'Verification approved.');

        return back()->with('success', 'Instructor verified successfully. QR code generated.');
    }

    /**
     * Reject an instructor application.
     */
    public function reject(Request $request, User $instructor): RedirectResponse
    {
        $this->authorize('verify', $instructor->instructorProfile);

        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $profile = $instructor->instructorProfile;

        if (! $profile) {
            return back()->with('error', 'Instructor profile not found.');
        }

        $oldStatus = $profile->status;

        $profile->update([
            'status' => InstructorStatus::Rejected->value,
        ]);

        // Send rejection notification
        try {
            $instructor->notify(new InstructorRejected($validated['reason']));
        } catch (\Exception $e) {
            \Log::error('Failed to send rejection notification: '.$e->getMessage());
        }

        $this->broadcastStatusChange($instructor, $oldStatus, InstructorStatus::Rejected, $validated['reason']);

        return back()->with('success', 'Instructor application rejected.');
    }

    /**
     * Suspend an instructor.
     */
    public function suspend(Request $request, User $instructor, SuspensionService $suspensionService): RedirectResponse
    {
        $this->authorize('suspend', $instructor->instructorProfile);

        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:2000',
            'duration_days' => 'required|integer|min:1|max:365',
        ]);

        $profile = $instructor->instructorProfile;

        if (! $profile) {
            return back()->with('error', 'Instructor profile not found.');
        }

        $suspensionService->suspendManually(
            $instructor,
            $validated['reason'],
            (int) $validated['duration_days'],
            $request->user()
        );

        return back()->with('success', 'Instructor suspended successfully. The reason is visible on their account.');
    }

    /**
     * Reactivate a suspended instructor.
     */
    public function reactivate(User $instructor, SuspensionService $suspensionService): RedirectResponse
    {
        $this->authorize('suspend', $instructor->instructorProfile);

        $profile = $instructor->instructorProfile;

        if (! $profile) {
            return back()->with('error', 'Instructor profile not found.');
        }

        $suspension = InstructorSuspension::where('instructor_id', $instructor->id)
            ->where('status', SuspensionStatus::Active->value)
            ->latest()
            ->first();

        if ($suspension) {
            // Automatic/legacy suspensions clear the served strike slate;
            // a manual suspension does not change the strike ledger.
            $resetStrikes = $suspension->type !== SuspensionType::Manual;

            $suspensionService->lift(
                $suspension,
                'Reactivated by SISA.',
                request()->user(),
                SuspensionStatus::RevokedByAdmin,
                $resetStrikes
            );
        } else {
            $oldStatus = $profile->status;

            $profile->update([
                'status' => InstructorStatus::Active->value,
                'suspended_until' => null,
                'suspension_reason' => null,
                'suspended_at' => null,
                'strike_count' => 0,
            ]);

            InstructorStatusLog::create([
                'instructor_id' => $instructor->id,
                'from_status' => $oldStatus?->value,
                'to_status' => InstructorStatus::Active->value,
                'reason' => 'Reactivated by SISA.',
                'changed_by' => request()->user()?->id,
            ]);

            try {
                $instructor->notify(new InstructorReactivated);
            } catch (\Throwable $e) {
                \Log::error('Failed to send reactivation notification: '.$e->getMessage());
            }

            $this->broadcastStatusChange($instructor, $oldStatus, InstructorStatus::Active, 'Reactivated by SISA.');
        }

        return back()->with('success', 'Instructor reactivated successfully.');
    }

    /**
     * Phase 11 - every status change is broadcast on `instructor.{id}` and
     * `admin.notifications` so the frontend listeners actually fire. A dead
     * socket must never fail the admin action that triggered it.
     */
    private function broadcastStatusChange(
        User $instructor,
        InstructorStatus $oldStatus,
        InstructorStatus $newStatus,
        ?string $reason = null
    ): void {
        if ($oldStatus === $newStatus) {
            return;
        }

        try {
            broadcast(new InstructorStatusChanged(
                $instructor,
                $oldStatus,
                $newStatus,
                $reason
            ))->toOthers();
        } catch (\Throwable $exception) {
            \Log::warning('Failed to broadcast instructor status change.', [
                'instructor_id' => $instructor->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Show the form for editing an instructor.
     */
    public function edit(User $instructor): Response
    {
        $instructor->load('instructorProfile.surfSpots');
        $profile = $instructor->instructorProfile;

        return Inertia::render('Admin/Instructors/Edit', [
            'instructor' => $instructor,
            'availableSurfSpots' => SurfSpot::query()
                ->active()
                ->orderByRaw("CASE difficulty WHEN 'beginner' THEN 1 WHEN 'intermediate' THEN 2 ELSE 3 END")
                ->orderBy('name')
                ->get()
                ->map(fn (SurfSpot $spot): array => $spot->toMapPayload())
                ->values()
                ->all(),
            'selectedSurfSpotIds' => $profile?->activeAllowedSurfSpots()
                ->pluck('id')
                ->values()
                ->all() ?? [],
            'offeredServices' => $profile?->offeredServiceValues() ?? [],
        ]);
    }

    /**
     * Update the specified instructor.
     */
    public function update(Request $request, User $instructor): RedirectResponse
    {
        $level = (int) $request->input('level', $instructor->instructorProfile?->level?->value ?? 1);
        $hasOfferedServices = $request->has('offered_services');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$instructor->id,
            'phone' => 'nullable|string|max:20',
            'bio' => 'nullable|string',
            'level' => 'required|integer|in:1,2,3',
            ...PricingService::offeringValidationRules($level, $request->input('offered_services'), $hasOfferedServices),
            ...SurfSpot::instructorSelectionValidationRules($level, requireWhenPresent: false),
            'status' => 'required|string|in:'.implode(',', array_column(InstructorStatus::cases(), 'value')),
            'area' => 'nullable|string|max:100',
            'meeting_point' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:9.5,10.2',
            'longitude' => 'nullable|numeric|between:125.8,126.5',
        ], PricingService::offeringValidationMessages($level));

        $profile = $instructor->instructorProfile;
        $wasActive = $profile->status === InstructorStatus::Active;
        $becomingActive = $validated['status'] === InstructorStatus::Active->value;

        // BR-2.1: Find Instructor only lists active instructors who also have at
        // least one verified certificate. When an admin sets status to "Active
        // (Verified)" from Edit Dossier, approve any pending certificates so
        // the instructor does not end up active-but-hidden from tourists.
        if ($becomingActive && ! $instructor->hasVerifiedCertificate()) {
            $verifiedPending = $instructor->certificates()
                ->where('status', CertificateStatus::PendingVerification->value)
                ->update([
                    'status' => CertificateStatus::Verified->value,
                    'verified_at' => now(),
                ]);

            if (! $wasActive && $verifiedPending === 0) {
                return back()
                    ->withErrors([
                        'status' => 'Cannot mark an instructor Active (Verified) without at least one uploaded certificate.',
                    ])
                    ->withInput();
            }
        }

        // Update user
        $instructor->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
        ]);

        $offerings = PricingService::normalizeOfferingsForLevel(
            (int) $validated['level'],
            $validated,
            $hasOfferedServices,
            $instructor->instructorProfile
        );

        $profile = $instructor->instructorProfile;

        // Update profile
        $profile->update([
            'bio' => $validated['bio'] ?? null,
            'level' => $validated['level'],
            'status' => $validated['status'],
            ...$offerings,
            'area' => $validated['area'] ?? null,
            'meeting_point' => $validated['meeting_point'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
        ]);

        if (array_key_exists('surf_spot_ids', $validated)) {
            $profile->syncSurfSpotsAndLocation(
                $validated['surf_spot_ids'] ?? [],
                syncCoordinates: ! $request->filled('latitude') && ! $request->filled('longitude'),
                submittedArea: $validated['area'] ?? null,
            );
        }

        $profile->pruneForCurrentLevel();

        if ($becomingActive && empty($profile->qr_code_path)) {
            $qrPath = $this->qrCodeService->generateInstructorQR($profile->fresh());
            $profile->update(['qr_code_path' => $qrPath]);
        }

        return redirect()
            ->route('admin.instructors.show', $instructor->id)
            ->with('success', 'Instructor dossier updated successfully.');
    }
}
