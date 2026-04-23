<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\SurfSpot;
use App\Enums\InstructorStatus;
use App\Enums\InstructorLevel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InstructorSearchController extends Controller
{
    /**
     * Display instructor search page with filters
     */
    public function index(Request $request): Response
    {
        $query = User::with('instructorProfile')
            ->where('role', 'instructor')
            ->whereHas('instructorProfile', function ($q) {
                $q->where('status', InstructorStatus::Active->value)
                  ->where(function ($subQ) {
                      $subQ->whereNull('suspended_until')
                           ->orWhere('suspended_until', '<=', now());
                  });
            });

        // Skill Level Matching - Show instructors who can teach the student's skill level
        $skillLevel = null;
        
        if (auth()->check() && auth()->user()->isStudent() && auth()->user()->studentProfile) {
            // Match based on student profile if logged in
            $skillLevel = auth()->user()->studentProfile->skill_level;
        } elseif ($request->has('skill_level')) {
            $skillLevel = $request->skill_level;
        }

        if ($skillLevel) {
            $query->whereHas('instructorProfile', function ($q) use ($skillLevel) {
                // Level 1 instructors can teach beginners
                // Level 2 and Level 3 instructors can teach all skill levels
                if ($skillLevel === 'beginner') {
                    // All instructor levels can teach beginners
                    $q->whereIn('level', [1, 2, 3]);
                } elseif ($skillLevel === 'intermediate') {
                    // Only Level 2 and Level 3 can teach intermediate
                    $q->whereIn('level', [2, 3]);
                } elseif ($skillLevel === 'advanced') {
                    // Only Level 2 and Level 3 can teach advanced
                    $q->whereIn('level', [2, 3]);
                }
            });
        }

        // Filter by instructor level
        if ($request->filled('level')) {
            $query->whereHas('instructorProfile', function ($q) use ($request) {
                $q->where('level', $request->level);
            });
        }

        // Filter by price range
        if ($request->filled('min_price')) {
            $query->whereHas('instructorProfile', function ($q) use ($request) {
                $q->where('rate_per_hour', '>=', $request->min_price);
            });
        }
        if ($request->filled('max_price')) {
            $query->whereHas('instructorProfile', function ($q) use ($request) {
                $q->where('rate_per_hour', '<=', $request->max_price);
            });
        }

        // Full-text search on name and bio
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhereHas('instructorProfile', function ($subQ) use ($search) {
                      $subQ->whereFullText('bio', $search);
                  });
            });
        }

        // Sort options
        $sortBy = $request->input('sort_by', 'recommended');
        switch ($sortBy) {
            case 'price_low':
                $query->join('instructor_profiles', 'users.id', '=', 'instructor_profiles.user_id')
                      ->orderBy('instructor_profiles.rate_per_hour', 'asc')
                      ->select('users.*');
                break;
            case 'price_high':
                $query->join('instructor_profiles', 'users.id', '=', 'instructor_profiles.user_id')
                      ->orderBy('instructor_profiles.rate_per_hour', 'desc')
                      ->select('users.*');
                break;
            case 'rating':
                // Sort by average rating (requires reviews relationship)
                $query->withAvg('reviewsAsInstructor', 'rating')
                      ->orderByDesc('reviews_as_instructor_avg_rating');
                break;
            case 'experience':
                $query->orderByDesc('created_at');
                break;
            default: // recommended - mix of rating and availability
                $query->withAvg('reviewsAsInstructor', 'rating')
                      ->orderByDesc('reviews_as_instructor_avg_rating')
                      ->orderBy('created_at', 'desc');
        }

        $instructors = $query->paginate(12)
            ->withQueryString();

        // Get surf spots for filtering
        $surfSpots = SurfSpot::where('is_active', true)
            ->select('id', 'name', 'difficulty')
            ->get();

        return Inertia::render('Public/Instructors/Search', [
            'instructors' => $instructors,
            'surfSpots' => $surfSpots,
            'filters' => $request->only(['search', 'skill_level', 'level', 'min_price', 'max_price', 'sort_by']),
        ]);
    }

    /**
     * Display single instructor profile
     */
    public function show(User $instructor): Response
    {
        $instructor->load([
            'instructorProfile',
            'certificates' => function ($q) {
                $q->where('status', 'verified');
            },
            'reviewsAsInstructor' => function ($q) {
                $q->with('student')
                  ->latest()
                  ->limit(10);
            },
            'availabilities' => function ($q) {
                $q->where('is_available', true)
                  ->where('specific_date', '>=', now()->format('Y-m-d'))
                  ->orWhereNull('specific_date');
            }
        ]);

        // Calculate average rating
        $averageRating = $instructor->reviewsAsInstructor()->avg('rating') ?? 0;
        $totalReviews = $instructor->reviewsAsInstructor()->count();

        return Inertia::render('Public/Instructors/Profile', [
            'instructor' => $instructor,
            'averageRating' => round($averageRating, 1),
            'totalReviews' => $totalReviews,
        ]);
    }
}