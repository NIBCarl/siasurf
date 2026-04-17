<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SkillUpgradeRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SkillUpgradeController extends Controller
{
    public function index(): Response
    {
        $requests = SkillUpgradeRequest::with(['student', 'instructor', 'booking'])
            ->latest()
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

        return back()->with('success', 'Skill upgrade rejected.');
    }
}
