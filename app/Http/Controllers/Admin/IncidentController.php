<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FeedbackCategory;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Events\IncidentReported;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmIncidentRequest;
use App\Http\Requests\RejectIncidentRequest;
use App\Http\Requests\StoreIncidentRequest;
use App\Models\SafetyIncident;
use App\Models\User;
use App\Notifications\IncidentOutcomeNotification;
use App\Notifications\InstructorStrikeRecordedNotification;
use App\Services\StrikeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status', 'all');
        $source = $request->query('source');
        $severity = $request->query('severity');
        $instructorId = $request->query('instructor_id');

        $query = SafetyIncident::with(['instructor', 'booking', 'reporter', 'review'])
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($source) {
            $query->where('source', $source);
        }

        if ($severity) {
            $query->where('severity', $severity);
        }

        if ($instructorId) {
            $query->where('instructor_id', $instructorId);
        }

        return Inertia::render('Admin/Incidents/Index', [
            'incidents' => $query->paginate(20)->withQueryString(),
            'filters' => [
                'status' => $status,
                'source' => $source,
                'severity' => $severity,
                'instructor_id' => $instructorId,
            ],
            'counts' => [
                'pending' => SafetyIncident::where('status', IncidentStatus::Pending->value)->count(),
                'confirmed' => SafetyIncident::where('status', IncidentStatus::Confirmed->value)->count(),
                'rejected' => SafetyIncident::where('status', IncidentStatus::Rejected->value)->count(),
                'revoked' => SafetyIncident::where('status', IncidentStatus::Revoked->value)->count(),
            ],
            'instructors' => User::where('role', 'instructor')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Incidents/Create', [
            'instructors' => User::where('role', 'instructor')
                ->whereHas('instructorProfile')
                ->with('instructorProfile')
                ->get(),
            'bookings' => \App\Models\Booking::with(['student', 'instructor'])
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(),
            'categories' => FeedbackCategory::concernOptions(),
        ]);
    }

    public function store(StoreIncidentRequest $request, StrikeService $strikeService): RedirectResponse
    {
        $validated = $request->validated();
        $instructor = User::where('role', 'instructor')->findOrFail($validated['instructor_id']);
        $severity = IncidentSeverity::from($validated['severity']);
        $category = isset($validated['category']) ? FeedbackCategory::tryFrom($validated['category']) : null;

        $incident = SafetyIncident::create([
            'booking_id' => $validated['booking_id'] ?? null,
            'instructor_id' => $instructor->id,
            'reported_by' => $request->user()->id,
            'source' => IncidentSource::Admin,
            'status' => IncidentStatus::Confirmed,
            'category' => $category?->value,
            'type' => IncidentType::from($validated['type']),
            'severity' => $severity,
            'suggested_severity' => $severity,
            'strike_weight' => $severity->strikes(),
            'description' => $validated['description'],
            'instructor_summary' => $validated['instructor_summary'] ?? null,
            'location' => $validated['location'] ?? null,
            'confirmed_at' => now(),
            'confirmed_by' => $request->user()->id,
        ]);

        $strike = $strikeService->applyForIncident($incident->fresh(), $request->user());

        if ($strike) {
            try {
                $instructor->notify(new InstructorStrikeRecordedNotification($strike));
            } catch (\Throwable $exception) {
                \Log::warning('Failed to notify instructor about strike.', [
                    'incident_id' => $incident->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        try {
            event(new IncidentReported($incident->fresh(['instructor', 'booking', 'reporter', 'review'])));
        } catch (\Throwable $exception) {
            \Log::warning('Failed to broadcast incident report.', [
                'incident_id' => $incident->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('admin.incidents.show', $incident)
            ->with('success', 'Incident recorded and strike applied.');
    }

    public function show(SafetyIncident $incident): Response
    {
        $incident->load([
            'instructor.instructorProfile',
            'booking.student',
            'booking.surfSpot',
            'reporter',
            'review',
            'strike',
            'confirmer',
            'rejecter',
        ]);

        return Inertia::render('Admin/Incidents/Show', [
            'incident' => $incident,
            'activeStrikes' => $incident->instructor?->instructorStrikes()
                ->with('incident')
                ->where('status', \App\Enums\StrikeStatus::Active->value)
                ->orderByDesc('applied_at')
                ->get(),
            'categories' => FeedbackCategory::concernOptions(),
        ]);
    }

    public function confirm(
        ConfirmIncidentRequest $request,
        SafetyIncident $incident,
        StrikeService $strikeService
    ): RedirectResponse {
        if ($incident->status !== IncidentStatus::Pending) {
            return back()->with('error', 'Only a pending report can be confirmed.');
        }

        $validated = $request->validated();
        $severity = IncidentSeverity::from($validated['severity']);

        $summary = $validated['instructor_summary'] ?? $incident->instructor_summary;
        if (! $summary && ! empty($validated['admin_note'])) {
            $summary = $validated['admin_note'];
        }

        $incident->update([
            'status' => IncidentStatus::Confirmed,
            'category' => $validated['category'] ?? $incident->category,
            'severity' => $severity,
            'strike_weight' => $severity->strikes(),
            'instructor_summary' => $summary,
            'confirmed_at' => now(),
            'confirmed_by' => $request->user()->id,
            'rejected_at' => null,
            'rejected_by' => null,
            'rejection_reason' => null,
            'resolved_at' => null,
        ]);

        $incident = $incident->fresh(['instructor.instructorProfile', 'reporter', 'booking', 'review']);
        $strike = $strikeService->applyForIncident($incident, $request->user());

        if ($strike) {
            try {
                $incident->instructor->notify(new InstructorStrikeRecordedNotification($strike));
            } catch (\Throwable $exception) {
                \Log::warning('Failed to notify instructor about confirmed strike.', [
                    'incident_id' => $incident->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->notifyReporter($incident);

        try {
            event(new IncidentReported($incident->fresh(['instructor', 'booking', 'reporter', 'review'])));
        } catch (\Throwable $exception) {
            \Log::warning('Failed to broadcast confirmed incident.', [
                'incident_id' => $incident->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $message = $strike
            ? 'Report confirmed. ' . $strike->weight . ' strike(s) added to the instructor record.'
            : 'Report confirmed, but no strike was added.';

        return back()->with('success', $message);
    }

    public function reject(RejectIncidentRequest $request, SafetyIncident $incident): RedirectResponse
    {
        if ($incident->status !== IncidentStatus::Pending) {
            return back()->with('error', 'Only a pending report can be rejected.');
        }

        $incident->update([
            'status' => IncidentStatus::Rejected,
            'rejected_at' => now(),
            'rejected_by' => $request->user()->id,
            'rejection_reason' => $request->validated()['reason'],
            'resolved_at' => now(),
        ]);

        $this->notifyReporter($incident->fresh(['reporter', 'instructor', 'booking']));

        return back()->with('success', 'Report rejected. No strike was added.');
    }

    public function revoke(
        RejectIncidentRequest $request,
        SafetyIncident $incident,
        StrikeService $strikeService,
        \App\Services\SuspensionService $suspensionService
    ): RedirectResponse {
        if ($incident->status !== IncidentStatus::Confirmed) {
            return back()->with('error', 'Only a confirmed incident can be revoked.');
        }

        $strikeService->revokeIncident(
            $incident,
            $request->validated()['reason'],
            $request->user()
        );

        $profile = $incident->instructor?->instructorProfile()->first();

        if ($profile && $profile->strike_count < StrikeService::SUSPENSION_THRESHOLD) {
            $suspension = \App\Models\InstructorSuspension::where('instructor_id', $incident->instructor_id)
                ->where('status', \App\Enums\SuspensionStatus::Active->value)
                ->where('type', \App\Enums\SuspensionType::Automatic->value)
                ->latest()
                ->first();

            if ($suspension) {
                $suspensionService->lift(
                    $suspension,
                    'Automatic suspension lifted after the confirmed strike was revoked.',
                    $request->user(),
                    \App\Enums\SuspensionStatus::RevokedByAdmin,
                    false
                );
            }
        }

        return back()->with('success', 'Incident strike revoked. The instructor record was updated.');
    }

    public function evidence(SafetyIncident $incident)
    {
        if (! $incident->evidence_path || ! Storage::disk('local')->exists($incident->evidence_path)) {
            abort(404, 'Evidence file not found.');
        }

        return Storage::disk('local')->download(
            $incident->evidence_path,
            'incident-evidence-' . $incident->id . '-' . basename($incident->evidence_path)
        );
    }

    private function notifyReporter(SafetyIncident $incident): void
    {
        if (! $incident->reporter) {
            return;
        }

        try {
            $incident->reporter->notify(new IncidentOutcomeNotification($incident));
        } catch (\Throwable $exception) {
            \Log::warning('Failed to notify reporter about incident outcome.', [
                'incident_id' => $incident->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

