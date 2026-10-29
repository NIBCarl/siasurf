<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\InstructorStatus;
use App\Enums\StrikeStatus;
use App\Enums\SuspensionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppealRequest;
use App\Models\InstructorSuspension;
use App\Services\AppealService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuspensionController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->isInstructor() || $user->instructorProfile?->status !== InstructorStatus::Suspended) {
            return redirect()->route('dashboard');
        }

        $suspension = InstructorSuspension::where('instructor_id', $user->id)
            ->where('status', SuspensionStatus::Active->value)
            ->latest()
            ->first();

        $appeal = $suspension?->appeals()->latest()->first();

        $strikes = $user->instructorStrikes()
            ->with('incident')
            ->where('status', StrikeStatus::Active->value)
            ->orderByDesc('applied_at')
            ->get()
            ->map(fn ($strike) => [
                'id' => $strike->id,
                'weight' => $strike->weight,
                'reason' => $strike->incident?->instructor_summary
                    ?? $strike->reason_text
                    ?? $strike->reason_code,
                'category' => $strike->incident?->category,
                'applied_at' => $strike->applied_at?->format('M d, Y'),
            ])
            ->values();

        return Inertia::render('Instructor/Suspended', [
            'profile' => [
                'strike_count' => $user->instructorProfile->strike_count,
                'suspension_reason' => $user->instructorProfile->suspension_reason,
                'suspended_at' => $user->instructorProfile->suspended_at?->format('M d, Y g:i A'),
                'suspended_until' => $user->instructorProfile->suspended_until?->format('M d, Y g:i A'),
            ],
            'suspension' => $suspension ? [
                'id' => $suspension->id,
                'type' => $suspension->type?->value,
                'type_label' => $suspension->type?->label(),
                'status' => $suspension->status?->value,
                'reason' => $suspension->reason,
                'starts_at' => $suspension->starts_at?->format('M d, Y g:i A'),
                'ends_at' => $suspension->ends_at?->format('M d, Y g:i A'),
            ] : null,
            'strikes' => $strikes,
            'appeal' => $appeal ? [
                'id' => $appeal->id,
                'status' => $appeal->status?->value,
                'status_label' => $appeal->status?->label(),
                'message' => $appeal->message,
                'admin_response' => $appeal->admin_response,
                'created_at' => $appeal->created_at?->format('M d, Y g:i A'),
                'reviewed_at' => $appeal->reviewed_at?->format('M d, Y g:i A'),
            ] : null,
            'canAppeal' => $suspension !== null && $appeal === null,
            'appealGrounds' => collect(\App\Enums\AppealGround::cases())->map(fn ($ground) => [
                'value' => $ground->value,
                'label' => $ground->label(),
            ])->values(),
        ]);
    }

    public function appeal(StoreAppealRequest $request, AppealService $appealService): RedirectResponse
    {
        $user = $request->user();

        $suspension = InstructorSuspension::where('instructor_id', $user->id)
            ->where('status', SuspensionStatus::Active->value)
            ->latest()
            ->first();

        if (! $suspension) {
            return back()->with('error', 'There is no active suspension to appeal.');
        }

        $data = $request->validated();
        $data['evidence_path'] = $request->file('evidence')?->store('appeals', 'local');

        $appealService->submit($user, $suspension, $data);

        return back()->with('success', 'Your appeal was submitted. SISA will review it and send a decision.');
    }
}
