<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SkillUpgradeRequest;
use App\Notifications\SkillUpgradeResolvedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class SkillUpgradeController extends Controller
{
    public function index(): Response
    {
        $requests = SkillUpgradeRequest::with(['student', 'instructor', 'booking'])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END ASC', ['pending'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);

        return Inertia::render('Admin/SkillUpgrades/Index', [
            'upgradeRequests' => $requests
        ]);
    }

    public function approve(SkillUpgradeRequest $skillUpgradeRequest)
    {
        if ($skillUpgradeRequest->status !== 'pending') {
            return back()->with('error', 'Request has already been processed.');
        }

        // Update student profile
        $studentProfile = $skillUpgradeRequest->student->studentProfile;
        if ($studentProfile) {
            $studentProfile->skill_level = $skillUpgradeRequest->requested_level;
            $studentProfile->save();
        }

        // Mark as approved
        $skillUpgradeRequest->status = 'approved';
        $skillUpgradeRequest->save();

        $this->notifyParties($skillUpgradeRequest, approved: true);

        return back()->with('success', 'Skill upgrade approved successfully.');
    }

    public function reject(SkillUpgradeRequest $skillUpgradeRequest)
    {
        if ($skillUpgradeRequest->status !== 'pending') {
            return back()->with('error', 'Request has already been processed.');
        }

        // Mark as rejected
        $skillUpgradeRequest->status = 'rejected';
        $skillUpgradeRequest->save();

        $this->notifyParties($skillUpgradeRequest, approved: false);

        return back()->with('success', 'Skill upgrade rejected.');
    }

    private function notifyParties(SkillUpgradeRequest $skillUpgradeRequest, bool $approved): void
    {
        $fresh = $skillUpgradeRequest->fresh(['student', 'instructor', 'booking']);
        if (! $fresh) {
            return;
        }

        $notification = new SkillUpgradeResolvedNotification($fresh, $approved);

        foreach ([$fresh->student, $fresh->instructor] as $recipient) {
            if (! $recipient) {
                continue;
            }

            try {
                $recipient->notify($notification);
            } catch (\Throwable $exception) {
                Log::warning('Failed to notify party of skill upgrade outcome.', [
                    'skill_upgrade_request_id' => $fresh->id,
                    'user_id' => $recipient->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
