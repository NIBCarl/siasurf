<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\StudentAccountStatusNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $query = User::students()
            ->with(['studentProfile'])
            ->withCount([
                'bookingsAsStudent',
                'bookingsAsStudent as underpaid_bookings_count' => function ($q) {
                    $q->where('payment_status', 'underpaid');
                },
            ]);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $students = $query
            ->latest()
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Students/Index', [
            'students' => $students,
            'filters' => $request->only('search')
        ]);
    }

    public function show(User $user)
    {
        // Ensure user is a student
        if (!$user->isStudent()) {
            abort(404);
        }

        $user->load([
            'studentProfile',
            'latestSkillAssessment',
            'bookingsAsStudent' => function ($q) {
                $q->latest()->with(['instructor.instructorProfile', 'surfSpot', 'payment', 'waiver', 'review']);
            },
            'reportedIncidents'
        ]);

        $skillAssessment = $user->latestSkillAssessment
            ? app(\App\Services\SkillAssessmentService::class)->formatForDisplay($user->latestSkillAssessment)
            : null;

        return Inertia::render('Admin/Students/Show', [
            'student' => $user,
            'skillAssessment' => $skillAssessment,
        ]);
    }

    public function toggleStatus(User $user)
    {
        if (!$user->isStudent()) {
            abort(404);
        }

        $profile = $user->studentProfile;
        
        if (!$profile) {
            // Create a default profile if they somehow don't have one
            $profile = $user->studentProfile()->create([
                'status' => 'active',
                'skill_level' => 'beginner',
                'is_first_time' => true
            ]);
        }

        $isBanned = $profile->status === 'active';

        $profile->update([
            'status' => $isBanned ? 'banned' : 'active'
        ]);

        try {
            $user->notify(new StudentAccountStatusNotification($isBanned));
        } catch (\Throwable $exception) {
            Log::warning('Failed to notify student of account status change.', [
                'student_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $statusMessage = $profile->status === 'active' ? 'unbanned' : 'banned';

        return back()->with('success', "Student has been {$statusMessage} successfully.");
    }
}
