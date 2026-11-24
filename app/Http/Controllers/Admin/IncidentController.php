<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FeedbackCategory;
use App\Enums\IncidentOutcome;
use App\Enums\IncidentReportTopic;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\StrikeStatus;
use App\Events\IncidentReported;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmIncidentRequest;
use App\Http\Requests\RejectIncidentRequest;
use App\Http\Requests\StoreIncidentRequest;
use App\Models\Booking;
use App\Models\SafetyIncident;
use App\Models\User;
use App\Notifications\CriticalIncidentNoticeNotification;
use App\Notifications\IncidentOutcomeNotification;
use App\Notifications\InstructorStrikeRecordedNotification;
use App\Notifications\StrikeRevokedNotification;
use App\Services\StrikeService;
use App\Services\SuspensionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END ASC', [
                IncidentStatus::Pending->value,
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($status === 'critical_external') {
            $query->where('handled_externally', true);
        } elseif ($status !== 'all') {
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
                'critical_external' => SafetyIncident::where('handled_externally', true)->count(),
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
            'bookings' => Booking::with(['student', 'instructor'])
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(),
            'categories' => FeedbackCategory::concernOptions(),
            'reportTopics' => IncidentReportTopic::options(),
            'outcomeOptions' => IncidentOutcome::decisionOptions(includeDismissal: false),
        ]);
    }

    public function store(
        StoreIncidentRequest $request,
        StrikeService $strikeService,
        SuspensionService $suspensionService
    ): RedirectResponse {
        $validated = $request->validated();
        $instructor = User::where('role', 'instructor')->findOrFail($validated['instructor_id']);
        $outcome = IncidentOutcome::from($validated['final_outcome']);
        $category = isset($validated['category']) ? FeedbackCategory::tryFrom($validated['category']) : null;
        $reportType = $validated['report_type'] ?? $category?->reportTopic()->value;
        $isCritical = $outcome->isCritical();

        $incident = SafetyIncident::create([
            'booking_id' => $validated['booking_id'] ?? null,
            'instructor_id' => $instructor->id,
            'reported_by' => $request->user()->id,
            'source' => IncidentSource::Admin,
            'status' => IncidentStatus::Confirmed,
            'category' => $category?->value,
            'report_type' => $reportType,
            'checked_items' => $category ? [$category->value] : null,
            'type' => IncidentType::from($validated['type']),
            'severity' => $outcome->severity(),
            'suggested_severity' => $outcome->severity(),
            'suggested_outcome' => $outcome,
            'final_outcome' => $outcome,
            'strike_weight' => $outcome->strikes(),
            'handled_externally' => $isCritical,
            'external_handled_at' => $isCritical ? now() : null,
            'description' => $validated['description'],
            'instructor_summary' => $validated['instructor_summary'] ?? null,
            'decision_reason' => $validated['decision_reason'],
            'admin_note' => $validated['admin_note'],
            'location' => $validated['location'] ?? null,
            'confirmed_at' => now(),
            'confirmed_by' => $request->user()->id,
            'resolved_at' => $isCritical ? now() : null,
        ]);

        if ($isCritical) {
            if (! empty($validated['place_manual_hold'])) {
                $suspensionService->suspendManually(
                    $instructor,
                    $validated['instructor_summary'] ?: $validated['decision_reason'],
                    (int) $validated['manual_hold_days'],
                    $request->user()
                );
            } else {
                try {
                    $instructor->notify(new CriticalIncidentNoticeNotification($incident->fresh()));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to notify instructor of critical incident.', [
                        'incident_id' => $incident->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        } else {
            $strike = $strikeService->applyForIncident($incident->fresh(), $request->user());

            if ($strike) {
                try {
                    $activeTotal = $instructor->instructorProfile()->value('strike_count');
                    $instructor->notify(new InstructorStrikeRecordedNotification($strike, $activeTotal));
                } catch (\Throwable $exception) {
                    \Log::warning('Failed to notify instructor about strike.', [
                        'incident_id' => $incident->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
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

        $flash = $isCritical
            ? 'Critical incident recorded for external SISA handling (0 in-system strikes applied).'
            : 'Incident recorded and strike decision applied.';

        return redirect()->route('admin.incidents.show', $incident)
            ->with('success', $flash);
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
            'fine',
            'confirmer',
            'rejecter',
        ]);

        $instructor = $incident->instructor;
        $enforcedSuspension = $instructor?->activeInstructorSuspension();

        return Inertia::render('Admin/Incidents/Show', [
            'incident' => $incident,
            'activeStrikes' => $instructor?->instructorStrikes()
                ->with('incident')
                ->where('status', StrikeStatus::Active->value)
                ->orderByDesc('applied_at')
                ->get() ?? [],
            'dueFines' => $instructor?->instructorFines()
                ->due()
                ->orderByDesc('issued_at')
                ->get() ?? [],
            'enforcedSuspension' => $enforcedSuspension ? [
                'id' => $enforcedSuspension->id,
                'status' => $enforcedSuspension->status?->value,
                'status_label' => $enforcedSuspension->status?->label(),
                'tier_label' => $enforcedSuspension->tierLabel(),
                'strike_tier' => $enforcedSuspension->strike_tier,
                'ends_at' => $enforcedSuspension->ends_at?->toIso8601String(),
            ] : null,
            'currentActiveStrikeCount' => (int) ($instructor?->instructorProfile?->strike_count ?? 0),
            'categories' => FeedbackCategory::concernOptions(),
            'reportTopics' => IncidentReportTopic::options(),
            'outcomeOptions' => IncidentOutcome::decisionOptions(),
        ]);
    }

    public function confirm(
        ConfirmIncidentRequest $request,
        SafetyIncident $incident,
        StrikeService $strikeService,
        SuspensionService $suspensionService
    ): RedirectResponse {
        if ($incident->status !== IncidentStatus::Pending) {
            return back()->with('error', 'Only a pending report can be decided.');
        }

        $validated = $request->validated();
        $outcome = IncidentOutcome::from($validated['final_outcome']);
        $summary = $validated['instructor_summary'] ?: $incident->instructor_summary;

        if ($outcome->isDismissal()) {
            $incident->update([
                'status' => IncidentStatus::Rejected,
                'category' => $validated['category'] ?? $incident->category,
                'severity' => null,
                'final_outcome' => IncidentOutcome::NoStrike,
                'strike_weight' => 0,
                'handled_externally' => false,
                'instructor_summary' => $summary,
                'decision_reason' => $validated['decision_reason'],
                'admin_note' => $validated['admin_note'],
                'rejection_reason' => $validated['decision_reason'],
                'rejected_at' => now(),
                'rejected_by' => $request->user()->id,
                'resolved_at' => now(),
            ]);

            $this->notifyReporter($incident->fresh(['reporter', 'instructor', 'booking']));

            return back()->with('success', 'Report dismissed (no strike applied). Kept permanently on record.');
        }

        if ($outcome->isCritical()) {
            $incident->update([
                'status' => IncidentStatus::Confirmed,
                'category' => $validated['category'] ?? $incident->category,
                'severity' => IncidentSeverity::Critical,
                'final_outcome' => IncidentOutcome::Critical,
                'strike_weight' => 0,
                'handled_externally' => true,
                'external_handled_at' => now(),
                'instructor_summary' => $summary,
                'decision_reason' => $validated['decision_reason'],
                'admin_note' => $validated['admin_note'],
                'confirmed_at' => now(),
                'confirmed_by' => $request->user()->id,
                'rejected_at' => null,
                'rejected_by' => null,
                'rejection_reason' => null,
                'resolved_at' => now(),
            ]);

            $incident = $incident->fresh(['instructor.instructorProfile', 'reporter', 'booking', 'review']);

            if (! empty($validated['place_manual_hold']) && $incident->instructor) {
                $suspensionService->suspendManually(
                    $incident->instructor,
                    $summary ?: $validated['decision_reason'],
                    (int) $validated['manual_hold_days'],
                    $request->user()
                );
            } elseif ($incident->instructor) {
                try {
                    $incident->instructor->notify(new CriticalIncidentNoticeNotification($incident));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to notify instructor of critical incident.', [
                        'incident_id' => $incident->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $this->notifyReporter($incident);

            try {
                event(new IncidentReported($incident));
            } catch (\Throwable $exception) {
                \Log::warning('Failed to broadcast critical incident.', [
                    'incident_id' => $incident->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            return back()->with('success', 'Report confirmed as Critical and marked for SISA external handling (0 in-system strikes added).');
        }

        $incident->update([
            'status' => IncidentStatus::Confirmed,
            'category' => $validated['category'] ?? $incident->category,
            'severity' => $outcome->severity(),
            'final_outcome' => $outcome,
            'strike_weight' => $outcome->strikes(),
            'handled_externally' => false,
            'external_handled_at' => null,
            'instructor_summary' => $summary,
            'decision_reason' => $validated['decision_reason'],
            'admin_note' => $validated['admin_note'],
            'confirmed_at' => now(),
            'confirmed_by' => $request->user()->id,
            'rejected_at' => null,
            'rejected_by' => null,
            'rejection_reason' => null,
            'resolved_at' => null,
        ]);

        $incident = $incident->fresh(['instructor.instructorProfile', 'reporter', 'booking', 'review']);
        $strike = $strikeService->applyForIncident($incident, $request->user());

        if ($strike && $incident->instructor) {
            try {
                $activeTotal = $incident->instructor->instructorProfile()->value('strike_count');
                $incident->instructor->notify(new InstructorStrikeRecordedNotification($strike, $activeTotal));
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
            ? 'Report confirmed. '.$strike->weight.' strike(s) added to the instructor record.'
            : 'Report confirmed, but no strike was added.';

        return back()->with('success', $message);
    }

    public function reject(RejectIncidentRequest $request, SafetyIncident $incident): RedirectResponse
    {
        if ($incident->status !== IncidentStatus::Pending) {
            return back()->with('error', 'Only a pending report can be rejected.');
        }

        $validated = $request->validated();
        $reason = $validated['reason'];

        $incident->update([
            'status' => IncidentStatus::Rejected,
            'severity' => null,
            'final_outcome' => IncidentOutcome::NoStrike,
            'strike_weight' => 0,
            'handled_externally' => false,
            'decision_reason' => $reason,
            'admin_note' => $validated['admin_note'] ?? $reason,
            'rejected_at' => now(),
            'rejected_by' => $request->user()->id,
            'rejection_reason' => $reason,
            'resolved_at' => now(),
        ]);

        $this->notifyReporter($incident->fresh(['reporter', 'instructor', 'booking']));

        return back()->with('success', 'Report dismissed. No strike was added.');
    }

    public function revoke(
        RejectIncidentRequest $request,
        SafetyIncident $incident,
        StrikeService $strikeService
    ): RedirectResponse {
        if ($incident->status !== IncidentStatus::Confirmed) {
            return back()->with('error', 'Only a confirmed incident can be revoked.');
        }

        $reason = $request->validated()['reason'];

        $strikeService->revokeIncident(
            $incident,
            $reason,
            $request->user()
        );

        if ($incident->instructor) {
            $strikeService->reconcileSuspensionAfterRevocation(
                $incident->instructor,
                $request->user(),
                'Automatic suspension adjusted after confirmed strike revocation: '.$reason
            );

            try {
                $incident->instructor->notify(new StrikeRevokedNotification($incident->fresh(), $reason));
            } catch (\Throwable $exception) {
                Log::warning('Failed to notify instructor of strike revocation.', [
                    'incident_id' => $incident->id,
                    'instructor_id' => $incident->instructor_id,
                    'error' => $exception->getMessage(),
                ]);
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
            'incident-evidence-'.$incident->id.'-'.basename($incident->evidence_path)
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
