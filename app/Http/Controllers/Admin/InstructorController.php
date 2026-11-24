<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CertificateStatus;
use App\Enums\FineStatus;
use App\Enums\InstructorStatus;
use App\Enums\SuspensionStatus;
use App\Enums\SuspensionType;
use App\Events\InstructorStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\InstructorFine;
use App\Models\InstructorProfile;
use App\Models\InstructorStatusLog;
use App\Models\InstructorSuspension;
use App\Models\SurfSpot;
use App\Models\User;
use App\Notifications\FineSettledNotification;
use App\Notifications\InstructorLevelUpdatedNotification;
use App\Notifications\InstructorReactivated;
use App\Notifications\InstructorRejected;
use App\Notifications\InstructorVerified;
use App\Services\PricingService;
use App\Services\QRCodeService;
use App\Services\SuspensionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
            ->join('instructor_profiles', 'instructor_profiles.user_id', '=', 'users.id')
            ->select('users.*')
            ->where('users.role', 'instructor');

        // Filter by status
        if ($request->filled('status')) {
            $query->where('instructor_profiles.status', $request->status);
        }

        // Filter by level
        if ($request->filled('level')) {
            $query->where('instructor_profiles.level', $request->level);
        }

        // Search by name or email
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        $instructors = $query
            ->orderByRaw('CASE WHEN instructor_profiles.status = ? THEN 0 ELSE 1 END ASC', [
                InstructorStatus::PendingVerification->value,
            ])
            ->orderByDesc('users.created_at')
            ->orderByDesc('users.id')
            ->paginate(20)
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
            'birthday' => ['nullable', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString()],
            'level' => 'required|integer|in:1,2,3',
            ...PricingService::offeringValidationRules($level, $request->input('offered_services'), $hasOfferedServices),
            ...SurfSpot::instructorSelectionValidationRules($level, requireWhenPresent: false),
            'area' => 'nullable|string|max:100',
            'meeting_point' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:9.5,10.2',
            'longitude' => 'nullable|numeric|between:125.8,126.5',
        ], [
            ...PricingService::offeringValidationMessages($level),
            'birthday.before_or_equal' => 'Instructors must be at least 18 years old.',
        ]);

        // Create user
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'instructor',
            'phone' => $validated['phone'] ?? null,
            'email_verified_at' => now(),
        ]);

        $offerings = PricingService::normalizeOfferingsForLevel((int) $validated['level'], $validated, $hasOfferedServices);

        // Create instructor profile
        $profile = InstructorProfile::create([
            'user_id' => $user->id,
            'bio' => $validated['bio'] ?? null,
            'birthday' => $validated['birthday'] ?? null,
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
        abort_unless($instructor->isInstructor(), 404);

        $instructor->load([
            'instructorProfile.surfSpots',
            'certificates',
            'availabilities',
            'instructorStrikes',
            'instructorFines.incident',
        ]);

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

        $auditTrail = [];
        if ($instructor->created_at) {
            $auditTrail[] = [
                'label' => 'System registry',
                'date' => $instructor->created_at->format('Y-m-d'),
                'tone' => 'neutral',
            ];
        }
        $latestCert = $instructor->certificates->sortByDesc('created_at')->first();
        if ($latestCert?->created_at) {
            $auditTrail[] = [
                'label' => 'Certificate upload',
                'date' => $latestCert->created_at->format('Y-m-d'),
                'tone' => 'neutral',
            ];
        }
        if ($profile?->verified_at) {
            $auditTrail[] = [
                'label' => 'SISA verification',
                'date' => $profile->verified_at->format('Y-m-d'),
                'tone' => 'positive',
            ];
        }
        if ($profile?->rejected_at) {
            $auditTrail[] = [
                'label' => 'Application rejected',
                'date' => $profile->rejected_at->format('Y-m-d'),
                'tone' => 'critical',
            ];
        }
        foreach ($instructor->instructorStrikes->sortByDesc('applied_at')->take(5) as $index => $strike) {
            $auditTrail[] = [
                'label' => 'Strike #'.($index + 1).' ('.($strike->weight ?? 1).' pt)',
                'date' => ($strike->applied_at ?? $strike->created_at)?->format('Y-m-d') ?? '—',
                'tone' => 'critical',
            ];
        }

        $enforcedSuspension = $instructor->activeInstructorSuspension();
        $fines = $instructor->instructorFines
            ->sortByDesc('issued_at')
            ->map(fn (InstructorFine $fine) => [
                'id' => $fine->id,
                'amount' => (float) $fine->amount,
                'status' => $fine->status?->value,
                'status_label' => $fine->status?->label(),
                'reason' => $fine->reason,
                'issued_at' => $fine->issued_at?->format('M d, Y'),
                'paid_at' => $fine->paid_at?->format('M d, Y'),
                'voided_at' => $fine->voided_at?->format('M d, Y'),
                'settlement_note' => $fine->settlement_note,
                'safety_incident_id' => $fine->safety_incident_id,
            ])
            ->values();

        return Inertia::render('Admin/Instructors/Show', [
            'instructor' => $instructor,
            'serviceRates' => $profile?->serviceRates() ?? [],
            'surfSpots' => $profile?->activeAllowedSurfSpots()
                ->map(fn (SurfSpot $spot): array => $spot->toMapPayload())
                ->values()
                ->all() ?? [],
            'auditTrail' => $auditTrail,
            'qrImageUrl' => $instructor->instructorProfile?->qr_code_path
                ? route('qr.image', $instructor->id)
                : null,
            'verificationUrl' => route('walkin.scan', $instructor->id),
            'activeSuspension' => $enforcedSuspension ? [
                'id' => $enforcedSuspension->id,
                'type' => $enforcedSuspension->type?->value,
                'tier_label' => $enforcedSuspension->tierLabel(),
                'strike_tier' => $enforcedSuspension->strike_tier,
                'status' => $enforcedSuspension->status?->value,
                'status_label' => $enforcedSuspension->status?->label(),
                'reason' => $enforcedSuspension->reason,
                'starts_at' => $enforcedSuspension->starts_at?->format('M d, Y g:i A'),
                'ends_at' => $enforcedSuspension->ends_at?->format('M d, Y g:i A'),
                'requires_sisa_id_confiscation' => (bool) $enforcedSuspension->requires_sisa_id_confiscation,
                'is_awaiting_reinstatement' => $enforcedSuspension->isAwaitingReinstatement(),
            ] : null,
            'fines' => $fines,
            'hasDueFines' => $fines->contains(fn ($fine) => $fine['status'] === FineStatus::Due->value),
            'canReinstate' => $profile?->status === InstructorStatus::Suspended,
            'ledgerSummary' => app(\App\Services\EarningsLedgerService::class)->getInstructorSummary($instructor),
        ]);
    }

    /**
     * Verify an instructor.
     */
    public function verify(Request $request, User $instructor): RedirectResponse
    {
        abort_unless($instructor->isInstructor(), 404);

        $profile = $instructor->instructorProfile;

        if (! $profile) {
            return back()->with('error', 'Instructor profile not found.');
        }

        $this->authorize('verify', $profile);

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

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
            'rejection_reason' => null,
            'rejected_at' => null,
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
        abort_unless($instructor->isInstructor(), 404);

        $profile = $instructor->instructorProfile;

        if (! $profile) {
            return back()->with('error', 'Instructor profile not found.');
        }

        $this->authorize('verify', $profile);

        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $reason = trim($validated['reason']);
        $oldStatus = $profile->status;

        $profile->update([
            'status' => InstructorStatus::Rejected->value,
            'rejection_reason' => $reason,
            'rejected_at' => now(),
        ]);

        InstructorStatusLog::create([
            'instructor_id' => $instructor->id,
            'from_status' => $oldStatus?->value,
            'to_status' => InstructorStatus::Rejected->value,
            'reason' => $reason,
            'changed_by' => $request->user()?->id,
        ]);

        // Send rejection notification
        try {
            $instructor->notify(new InstructorRejected($reason));
        } catch (\Exception $e) {
            \Log::error('Failed to send rejection notification: '.$e->getMessage());
        }

        $this->broadcastStatusChange($instructor, $oldStatus, InstructorStatus::Rejected, $reason);

        return back()->with('success', 'Instructor application rejected.');
    }

    /**
     * Suspend an instructor.
     */
    public function suspend(Request $request, User $instructor, SuspensionService $suspensionService): RedirectResponse
    {
        abort_unless($instructor->isInstructor(), 404);

        $profile = $instructor->instructorProfile;

        if (! $profile) {
            return back()->with('error', 'Instructor profile not found.');
        }

        $this->authorize('suspend', $profile);

        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:2000',
            'duration_days' => 'required|integer|min:1|max:365',
        ]);

        $suspensionService->suspendManually(
            $instructor,
            $validated['reason'],
            (int) $validated['duration_days'],
            $request->user()
        );

        return back()->with('success', 'Instructor suspended successfully. The reason is visible on their account.');
    }

    /**
     * Approve reinstatement for a suspended instructor.
     */
    public function reactivate(Request $request, User $instructor, SuspensionService $suspensionService): RedirectResponse
    {
        abort_unless($instructor->isInstructor(), 404);

        $profile = $instructor->instructorProfile;

        if (! $profile) {
            return back()->with('error', 'Instructor profile not found.');
        }

        $this->authorize('suspend', $profile);

        $validated = $request->validate([
            'reinstatement_note' => 'nullable|string|max:2000',
        ]);

        $note = $validated['reinstatement_note'] ?? 'Reinstatement approved by SISA administrator.';

        $suspension = InstructorSuspension::where('instructor_id', $instructor->id)
            ->enforced()
            ->latest()
            ->first();

        if ($suspension) {
            // Tier 3 (1-month) and legacy suspensions clear served strikes on reinstatement;
            // Tier 2 (1-week) preserves the 2 active strikes and Manual holds leave strikes unchanged.
            $liftedStatus = $suspension->isAwaitingReinstatement()
                ? SuspensionStatus::Completed
                : SuspensionStatus::RevokedByAdmin;
            $resetStrikes = $suspension->shouldResetStrikesOnLift();

            $suspensionService->lift(
                $suspension,
                $note,
                $request->user(),
                $liftedStatus,
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
                'reason' => $note,
                'changed_by' => $request->user()?->id,
            ]);

            try {
                $instructor->notify(new InstructorReactivated);
            } catch (\Throwable $e) {
                \Log::error('Failed to send reactivation notification: '.$e->getMessage());
            }

            $this->broadcastStatusChange($instructor, $oldStatus, InstructorStatus::Active, $note);
        }

        return back()->with('success', 'Instructor reinstatement approved.');
    }

    /**
     * Record that an instructor settled a SISA fine outside the platform.
     */
    public function markFinePaid(Request $request, User $instructor, InstructorFine $fine): RedirectResponse
    {
        abort_unless($instructor->isInstructor() && $fine->instructor_id === $instructor->id, 404);

        $profile = $instructor->instructorProfile;
        if (! $profile) {
            return back()->with('error', 'Instructor profile not found.');
        }

        $this->authorize('suspend', $profile);

        if ($fine->status !== FineStatus::Due) {
            return back()->with('error', 'Only an unpaid due fine can be marked as paid.');
        }

        $validated = $request->validate([
            'settlement_note' => 'nullable|string|max:1000',
        ]);

        $fine->update([
            'status' => FineStatus::Paid,
            'paid_at' => now(),
            'settled_by' => $request->user()->id,
            'settlement_note' => $validated['settlement_note'] ?? 'Fine paid to SISA outside the system.',
        ]);

        try {
            $instructor->notify(new FineSettledNotification($fine->fresh(), waived: false));
        } catch (\Throwable $exception) {
            Log::warning('Failed to send fine paid notification.', [
                'fine_id' => $fine->id,
                'instructor_id' => $instructor->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Fine marked as paid.');
    }

    /**
     * Waive an unpaid SISA fine recorded on an instructor's account.
     */
    public function waiveFine(Request $request, User $instructor, InstructorFine $fine): RedirectResponse
    {
        abort_unless($instructor->isInstructor() && $fine->instructor_id === $instructor->id, 404);

        $profile = $instructor->instructorProfile;
        if (! $profile) {
            return back()->with('error', 'Instructor profile not found.');
        }

        $this->authorize('suspend', $profile);

        if ($fine->status !== FineStatus::Due) {
            return back()->with('error', 'Only an unpaid due fine can be waived.');
        }

        $validated = $request->validate([
            'settlement_note' => 'required|string|min:5|max:1000',
        ]);

        $fine->update([
            'status' => FineStatus::Waived,
            'settled_by' => $request->user()->id,
            'settlement_note' => $validated['settlement_note'],
        ]);

        try {
            $instructor->notify(new FineSettledNotification($fine->fresh(), waived: true));
        } catch (\Throwable $exception) {
            Log::warning('Failed to send fine waived notification.', [
                'fine_id' => $fine->id,
                'instructor_id' => $instructor->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Fine waived by SISA.');
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
        abort_unless($instructor->isInstructor(), 404);

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
        abort_unless($instructor->isInstructor(), 404);

        $profile = $instructor->instructorProfile;

        if (! $profile) {
            return back()->with('error', 'Instructor profile not found.');
        }

        $level = (int) $request->input('level', $profile->level?->value ?? 1);
        $hasOfferedServices = $request->has('offered_services');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$instructor->id,
            'phone' => 'nullable|string|max:20',
            'bio' => 'nullable|string',
            'birthday' => ['nullable', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString()],
            'level' => 'required|integer|in:1,2,3',
            ...PricingService::offeringValidationRules($level, $request->input('offered_services'), $hasOfferedServices),
            ...SurfSpot::instructorSelectionValidationRules($level, requireWhenPresent: false),
            'status' => 'required|string|in:'.implode(',', array_column(InstructorStatus::cases(), 'value')),
            'area' => 'nullable|string|max:100',
            'meeting_point' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:9.5,10.2',
            'longitude' => 'nullable|numeric|between:125.8,126.5',
        ], [
            ...PricingService::offeringValidationMessages($level),
            'birthday.before_or_equal' => 'Instructors must be at least 18 years old.',
        ]);

        $profile = $instructor->instructorProfile;
        $oldStatus = $profile->status;
        $oldLevel = $profile->level?->value;
        $wasSuspended = $profile->status === InstructorStatus::Suspended;
        $becomingSuspended = $validated['status'] === InstructorStatus::Suspended->value;

        if (($wasSuspended && ! $becomingSuspended) || (! $wasSuspended && $becomingSuspended)) {
            return back()
                ->withErrors([
                    'status' => $becomingSuspended
                        ? 'Use Suspend Profile on the instructor dossier so suspension rules and booking cancellations are applied.'
                        : 'Use Approve Reinstatement on the instructor dossier to lift a suspension.',
                ])
                ->withInput();
        }

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

        $clearRejection = $validated['status'] !== InstructorStatus::Rejected->value
            ? ['rejection_reason' => null, 'rejected_at' => null]
            : [];

        // Update profile
        $profileAttributes = [
            'bio' => $validated['bio'] ?? null,
            'level' => $validated['level'],
            'status' => $validated['status'],
            ...$clearRejection,
            ...$offerings,
            'area' => $validated['area'] ?? null,
            'meeting_point' => $validated['meeting_point'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
        ];

        if (array_key_exists('birthday', $validated)) {
            $profileAttributes['birthday'] = $validated['birthday'];
        }

        $profile->update($profileAttributes);

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

        $freshProfile = $profile->fresh();

        if (! $wasActive && $becomingActive && $freshProfile) {
            try {
                $instructor->notify(new InstructorVerified($freshProfile));
            } catch (\Throwable $exception) {
                Log::warning('Failed to send verification notification.', [
                    'instructor_id' => $instructor->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            if ($oldStatus) {
                $this->broadcastStatusChange($instructor, $oldStatus, InstructorStatus::Active, 'Verification approved.');
            }
        } elseif ($oldStatus && $oldStatus->value !== $validated['status']) {
            $newStatus = InstructorStatus::from($validated['status']);

            if ($newStatus === InstructorStatus::Rejected) {
                try {
                    $instructor->notify(new InstructorRejected('Your instructor application status was updated to rejected by SISA.'));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to send rejection notification.', [
                        'instructor_id' => $instructor->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $this->broadcastStatusChange($instructor, $oldStatus, $newStatus, 'Status updated by SISA administrator.');
        }

        if ($oldLevel !== null && (int) $validated['level'] !== (int) $oldLevel) {
            try {
                $instructor->notify(new InstructorLevelUpdatedNotification((int) $oldLevel, (int) $validated['level']));
            } catch (\Throwable $exception) {
                Log::warning('Failed to send level update notification.', [
                    'instructor_id' => $instructor->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return redirect()
            ->route('admin.instructors.show', $instructor->id)
            ->with('success', 'Instructor dossier updated successfully.');
    }
}
