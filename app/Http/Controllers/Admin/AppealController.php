<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppealStatus;
use App\Enums\StrikeStatus;
use App\Enums\SuspensionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveAppealRequest;
use App\Models\InstructorAppeal;
use App\Models\InstructorStrike;
use App\Services\AppealService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class AppealController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status', 'pending');

        $appeals = InstructorAppeal::with(['instructor', 'suspension'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Appeals/Index', [
            'appeals' => $appeals,
            'filters' => ['status' => $status],
            'counts' => [
                'pending' => InstructorAppeal::where('status', AppealStatus::Pending->value)->count(),
                'approved' => InstructorAppeal::where('status', AppealStatus::Approved->value)->count(),
                'rejected' => InstructorAppeal::where('status', AppealStatus::Rejected->value)->count(),
                'expired' => InstructorAppeal::where('status', AppealStatus::Expired->value)->count(),
            ],
        ]);
    }

    public function show(InstructorAppeal $appeal): Response
    {
        $appeal->load([
            'instructor.instructorProfile',
            'suspension',
            'reviewedBy',
        ]);

        $activeStrikes = InstructorStrike::with('incident')
            ->where('instructor_id', $appeal->instructor_id)
            ->where('status', StrikeStatus::Active->value)
            ->orderByDesc('applied_at')
            ->get();

        return Inertia::render('Admin/Appeals/Show', [
            'appeal' => $appeal,
            'activeStrikes' => $activeStrikes,
            'suspensionActive' => $appeal->suspension?->status === SuspensionStatus::Active,
        ]);
    }

    public function evidence(InstructorAppeal $appeal)
    {
        if (! $appeal->evidence_path || ! Storage::disk('local')->exists($appeal->evidence_path)) {
            abort(404, 'Appeal evidence file not found.');
        }

        return Storage::disk('local')->download(
            $appeal->evidence_path,
            'appeal-evidence-' . $appeal->id . '-' . basename($appeal->evidence_path)
        );
    }

    public function approve(
        ResolveAppealRequest $request,
        InstructorAppeal $appeal,
        AppealService $appealService
    ): RedirectResponse {
        $appealService->approve(
            $appeal,
            $request->user(),
            $request->validated()['response'],
            $request->validated()['revoke_incident_ids'] ?? []
        );

        return back()->with('success', 'Appeal approved. The suspension was lifted and selected strikes were revoked.');
    }

    public function reject(
        ResolveAppealRequest $request,
        InstructorAppeal $appeal,
        AppealService $appealService
    ): RedirectResponse {
        $appealService->reject(
            $appeal,
            $request->user(),
            $request->validated()['response']
        );

        return back()->with('success', 'Appeal rejected. The suspension remains in effect.');
    }
}
