<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $query = User::students()
            ->with(['studentProfile'])
            ->withCount(['bookingsAsStudent']);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $students = $query->paginate(15)->withQueryString();

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
            'bookingsAsStudent' => function ($q) {
                $q->latest()->with(['instructor.instructorProfile', 'surfSpot', 'payment', 'waiver', 'review']);
            },
            'reportedIncidents'
        ]);

        return Inertia::render('Admin/Students/Show', [
            'student' => $user
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

        $profile->update([
            'status' => $profile->status === 'active' ? 'banned' : 'active'
        ]);

        $statusMessage = $profile->status === 'active' ? 'unbanned' : 'banned';

        return back()->with('success', "Student has been {$statusMessage} successfully.");
    }
}
