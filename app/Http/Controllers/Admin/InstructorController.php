<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CertificateStatus;
use App\Enums\InstructorStatus;
use App\Events\InstructorStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\InstructorProfile;
use App\Models\User;
use App\Notifications\InstructorReactivated;
use App\Notifications\InstructorRejected;
use App\Notifications\InstructorSuspended;
use App\Notifications\InstructorVerified;
use App\Services\PricingService;
use App\Services\QRCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
        return Inertia::render('Admin/Instructors/Create');
    }

    /**
     * Store a newly created instructor.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
            'bio' => 'nullable|string',
            'level' => 'required|integer|in:1,2,3',
            'rate_per_hour' => 'required|numeric|'.PricingService::rateRangeRule(),
            'area' => 'nullable|string|max:100',
            'meeting_point' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:9.5,10.2',
            'longitude' => 'nullable|numeric|between:125.8,126.5',
        ], [
            'rate_per_hour.min' => 'The base rate must be at least ₱'.number_format(PricingService::minRatePerHour()).' per hour.',
            'rate_per_hour.max' => 'The standard rate cannot exceed ₱'.number_format(PricingService::maxRatePerHour()).' per hour.',
            'rate_per_hour.numeric' => 'Enter the rate as a number, for example 800.',
        ]);

        // Create user
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'instructor',
            'phone' => $validated['phone'] ?? null,
        ]);

        // Create instructor profile
        InstructorProfile::create([
            'user_id' => $user->id,
            'bio' => $validated['bio'] ?? null,
            'level' => $validated['level'],
            'status' => InstructorStatus::PendingVerification->value,
            'rate_per_hour' => $validated['rate_per_hour'],
            'area' => $validated['area'] ?? null,
            'meeting_point' => $validated['meeting_point'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
        ]);

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
        $instructor->load(['instructorProfile', 'certificates', 'availabilities']);

        // Existing active instructors verified before the QR module existed may
        // not have an image on disk yet. Generate it lazily so the dossier is
        // always actionable.
        $profile = $instructor->instructorProfile;
        if ($profile && $profile->status === InstructorStatus::Active) {
            $path = $profile->qr_code_path;
            if (!$path || !\Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
                $this->qrCodeService->generateInstructorQR($profile);
                $instructor->load('instructorProfile');
            }
        }

        return Inertia::render('Admin/Instructors/Show', [
            'instructor' => $instructor,
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

        // Update certificates status if provided
        if ($request->has('certificate_ids') && ! empty($request->certificate_ids)) {
            Certificate::whereIn('id', $request->certificate_ids)
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
        $qrPath = $this->qrCodeService->generateInstructorQR($profile);

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
            'status' => InstructorStatus::Inactive->value,
        ]);

        // Send rejection notification
        try {
            $instructor->notify(new InstructorRejected($validated['reason']));
        } catch (\Exception $e) {
            \Log::error('Failed to send rejection notification: '.$e->getMessage());
        }

        $this->broadcastStatusChange($instructor, $oldStatus, InstructorStatus::Inactive, $validated['reason']);

        return back()->with('success', 'Instructor application rejected.');
    }

    /**
     * Suspend an instructor.
     */
    public function suspend(Request $request, User $instructor, \App\Services\SuspensionService $suspensionService): RedirectResponse
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
    public function reactivate(User $instructor, \App\Services\SuspensionService $suspensionService): RedirectResponse
    {
        $this->authorize('suspend', $instructor->instructorProfile);

        $profile = $instructor->instructorProfile;

        if (! $profile) {
            return back()->with('error', 'Instructor profile not found.');
        }

        $suspension = \App\Models\InstructorSuspension::where('instructor_id', $instructor->id)
            ->where('status', \App\Enums\SuspensionStatus::Active->value)
            ->latest()
            ->first();

        if ($suspension) {
            // Automatic/legacy suspensions clear the served strike slate;
            // a manual suspension does not change the strike ledger.
            $resetStrikes = $suspension->type !== \App\Enums\SuspensionType::Manual;

            $suspensionService->lift(
                $suspension,
                'Reactivated by SISA.',
                request()->user(),
                \App\Enums\SuspensionStatus::RevokedByAdmin,
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

            \App\Models\InstructorStatusLog::create([
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
        $instructor->load('instructorProfile');

        return Inertia::render('Admin/Instructors/Edit', [
            'instructor' => $instructor,
        ]);
    }

    /**
     * Update the specified instructor.
     */
    public function update(Request $request, User $instructor): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$instructor->id,
            'phone' => 'nullable|string|max:20',
            'bio' => 'nullable|string',
            'level' => 'required|integer|in:1,2,3',
            'rate_per_hour' => 'required|numeric|'.PricingService::rateRangeRule(),
            'status' => 'required|string',
            'area' => 'nullable|string|max:100',
            'meeting_point' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:9.5,10.2',
            'longitude' => 'nullable|numeric|between:125.8,126.5',
        ], [
            'rate_per_hour.min' => 'The base rate must be at least ₱'.number_format(PricingService::minRatePerHour()).' per hour.',
            'rate_per_hour.max' => 'The standard rate cannot exceed ₱'.number_format(PricingService::maxRatePerHour()).' per hour.',
            'rate_per_hour.numeric' => 'Enter the rate as a number, for example 800.',
        ]);

        // Update user
        $instructor->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
        ]);

        // Update profile
        $instructor->instructorProfile->update([
            'bio' => $validated['bio'] ?? null,
            'level' => $validated['level'],
            'status' => $validated['status'],
            'rate_per_hour' => $validated['rate_per_hour'],
            'area' => $validated['area'] ?? null,
            'meeting_point' => $validated['meeting_point'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
        ]);

        return redirect()
            ->route('admin.instructors.show', $instructor->id)
            ->with('success', 'Instructor dossier updated successfully.');
    }
}
