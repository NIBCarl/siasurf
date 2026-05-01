<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\InstructorProfile;
use App\Models\Certificate;
use App\Services\QRCodeService;
use App\Enums\InstructorStatus;
use App\Enums\CertificateStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

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
            'rate_per_hour' => 'required|numeric|min:600',
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

        return Inertia::render('Admin/Instructors/Show', [
            'instructor' => $instructor,
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
        
        if (!$profile) {
            return back()->with('error', 'Instructor profile not found.');
        }

        // Update certificates status if provided
        if ($request->has('certificate_ids') && !empty($request->certificate_ids)) {
            Certificate::whereIn('id', $request->certificate_ids)
                ->update([
                    'status' => CertificateStatus::Verified->value,
                    'verified_at' => now(),
                ]);
        }

        // Final safety check: Does the instructor have ANY verified certificates now?
        if (!$instructor->hasVerifiedCertificate()) {
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
            $instructor->notify(new \App\Notifications\InstructorVerified($profile->fresh()));
        } catch (\Exception $e) {
            \Log::error('Failed to send verification notification: ' . $e->getMessage());
        }

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
        
        if (!$profile) {
            return back()->with('error', 'Instructor profile not found.');
        }

        $profile->update([
            'status' => InstructorStatus::Inactive->value,
        ]);

        // Send rejection notification
        try {
            $instructor->notify(new \App\Notifications\InstructorRejected($validated['reason']));
        } catch (\Exception $e) {
            \Log::error('Failed to send rejection notification: ' . $e->getMessage());
        }

        return back()->with('success', 'Instructor application rejected.');
    }

    /**
     * Suspend an instructor.
     */
    public function suspend(Request $request, User $instructor): RedirectResponse
    {
        $this->authorize('suspend', $instructor->instructorProfile);

        $validated = $request->validate([
            'reason' => 'required|string',
            'duration_days' => 'required|integer|min:1',
        ]);

        $profile = $instructor->instructorProfile;
        
        $profile->status = InstructorStatus::Suspended;
        $profile->suspended_until = now()->addDays($validated['duration_days']);
        $profile->save();

        // Send suspension notification
        try {
            $instructor->notify(new \App\Notifications\InstructorSuspended($validated['reason'], $validated['duration_days']));
        } catch (\Exception $e) {
            \Log::error('Failed to send suspension notification: ' . $e->getMessage());
        }

        return back()->with('success', 'Instructor suspended successfully.');
    }

    /**
     * Reactivate a suspended instructor.
     */
    public function reactivate(User $instructor): RedirectResponse
    {
        $this->authorize('suspend', $instructor->instructorProfile);

        $profile = $instructor->instructorProfile;
        
        if (!$profile) {
            return back()->with('error', 'Instructor profile not found.');
        }

        $profile->update([
            'status' => InstructorStatus::Active->value,
            'suspended_until' => null,
            'strike_count' => 0, // Reset strikes on reactivation
        ]);

        // Send reactivation notification
        try {
            $instructor->notify(new \App\Notifications\InstructorReactivated());
        } catch (\Exception $e) {
            \Log::error('Failed to send reactivation notification: ' . $e->getMessage());
        }

        return back()->with('success', 'Instructor reactivated successfully.');
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
            'email' => 'required|email|unique:users,email,' . $instructor->id,
            'phone' => 'nullable|string|max:20',
            'bio' => 'nullable|string',
            'level' => 'required|integer|in:1,2,3',
            'rate_per_hour' => 'required|numeric|min:600',
            'status' => 'required|string',
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
        ]);

        return redirect()
            ->route('admin.instructors.show', $instructor->id)
            ->with('success', 'Instructor dossier updated successfully.');
    }
}