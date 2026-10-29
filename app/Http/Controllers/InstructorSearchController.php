<?php

namespace App\Http\Controllers;

use App\Enums\CertificateStatus;
use App\Enums\InstructorLevel;
use App\Enums\InstructorStatus;
use App\Enums\SkillLevel;
use App\Models\InstructorProfile;
use App\Models\SurfSpot;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\InstructorVerificationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InstructorSearchController extends Controller
{
    /**
     * Display instructor search page with filters
     */
    public function index(Request $request, AvailabilityService $availabilityService): Response
    {
        $query = User::with('instructorProfile')
            ->where('role', 'instructor')
            ->whereHas('instructorProfile', function ($q) {
                $q->where('status', InstructorStatus::Active->value)
                    ->where(function ($subQ) {
                        $subQ->whereNull('suspended_until')
                            ->orWhere('suspended_until', '<=', now());
                    });
            })
            // BR-2.1 - only instructors with a current verified certificate
            // are discoverable. This mirrors the QR verification page.
            ->whereHas('certificates', function ($q) {
                $q->where('status', CertificateStatus::Verified->value)
                    ->where(function ($certificate) {
                        $certificate->whereNull('expires_at')
                            ->orWhere('expires_at', '>=', now());
                    });
            });

        // Skill Level Matching - show only the instructors whose certification
        // covers this student's level (BR-3.6 / FR-2.x):
        //   Beginner     -> Levels 1, 2 and 3
        //   Intermediate -> Levels 2 and 3 (Level 1 is beginner-only)
        //   Advanced     -> Level 3 only
        $studentSkillLevel = null;

        if (auth()->check() && auth()->user()->isStudent() && auth()->user()->studentProfile) {
            // The profile is the source of truth: a student cannot widen their
            // own results by adding ?skill_level= to the URL.
            $studentSkillLevel = auth()->user()->studentProfile->skill_level;
        } elseif ($request->filled('skill_level')) {
            $studentSkillLevel = $request->query('skill_level');
        }

        $skillLevel = $studentSkillLevel instanceof SkillLevel
            ? $studentSkillLevel
            : ($studentSkillLevel ? SkillLevel::tryFrom((string) $studentSkillLevel) : null);

        $allowedInstructorLevels = null;

        if ($skillLevel) {
            $allowedInstructorLevels = $skillLevel->instructorLevels();

            $query->whereHas('instructorProfile', function ($q) use ($allowedInstructorLevels) {
                $q->whereIn('level', $allowedInstructorLevels);
            });
        }

        // Filter by instructor level. The requested level is intersected with
        // the levels this student is allowed to see, so `?level=1` can never
        // surface a beginner-only instructor to an advanced student.
        if ($request->filled('level')) {
            $requestedLevels = [(int) $request->query('level')];

            if ($allowedInstructorLevels !== null) {
                $requestedLevels = array_values(array_intersect($requestedLevels, $allowedInstructorLevels));
            }

            $query->whereHas('instructorProfile', function ($q) use ($requestedLevels) {
                // An empty intersection means "nothing is allowed"; -1 matches
                // no level, which is exactly the intended result.
                $q->whereIn('level', $requestedLevels === [] ? [-1] : $requestedLevels);
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

        // Directory location filter. The map and the list use the same filter,
        // so selecting "General Luna" never leaves pins from another area.
        if ($request->filled('area')) {
            $query->whereHas('instructorProfile', function ($q) use ($request) {
                $q->where('area', $request->area);
            });
        }

        // Full-text search on name and bio
        if ($request->has('search') && ! empty($request->search)) {
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
                $query->orderByDesc('reviews_as_instructor_avg_rating');
                break;
            case 'experience':
                $query->orderByDesc('created_at');
                break;
            default: // recommended - mix of rating and availability
                $query->orderByDesc('reviews_as_instructor_avg_rating')
                    ->orderBy('created_at', 'desc');
        }

        // Add the aggregates after sorting because price sorts replace the
        // select list with users.*; adding them last keeps the columns present.
        // Hidden (moderated) reviews are excluded so the directory rating
        // matches the rating shown on the public profile and the instructor
        // settings page.
        $query->withAvg(['reviewsAsInstructor' => fn ($reviews) => $reviews->visible()], 'rating')
            ->withCount(['reviewsAsInstructor' => fn ($reviews) => $reviews->visible()]);

        // The map is a directory, not a paginated table: show every matching
        // pin while the card list paginates. The cap prevents an accidental
        // unbounded response if the directory grows unusually large.
        $mapInstructors = (clone $query)
            ->limit(200)
            ->get()
            ->map(fn (User $instructor) => $this->transformInstructor($instructor))
            ->values();

        $instructors = $query->paginate(12)
            ->withQueryString();

        // "Available today" only for the cards actually on screen; the map
        // transform skips it because the pins do not show availability.
        $openPeriods = $availabilityService->openPeriodsForMany(
            $instructors->getCollection()->pluck('id')->all(),
            now(),
        );

        $instructors->through(fn (User $instructor) => $this->transformInstructor(
            $instructor,
            $openPeriods[$instructor->id] ?? [],
        ));

        // Get surf spots for filtering
        $surfSpots = SurfSpot::where('is_active', true)
            ->select('id', 'name', 'difficulty')
            ->get();

        $areas = InstructorProfile::query()
            ->whereNotNull('area')
            ->where('area', '!=', '')
            ->distinct()
            ->orderBy('area')
            ->pluck('area')
            ->values();

        return Inertia::render('Public/Instructors/Search', [
            'instructors' => $instructors,
            'mapInstructors' => $mapInstructors,
            'surfSpots' => $surfSpots,
            'areas' => $areas,
            'filters' => $request->only(['search', 'skill_level', 'level', 'min_price', 'max_price', 'area', 'sort_by']),
            // Explains why the directory is shaped the way it is. Null for
            // guests, who may browse every verified instructor.
            'skillMatch' => $skillLevel ? [
                'skill_level' => $skillLevel->value,
                'label' => $skillLevel->label(),
                'instructor_levels' => $skillLevel->instructorLevels(),
                'summary' => $skillLevel->instructorLevelRangeLabel(),
            ] : null,
            'mapCenter' => [
                'latitude' => 9.795,
                'longitude' => 126.155,
                'zoom' => 11,
            ],
        ]);
    }

    /**
     * Shape a directory result for both the paginated cards and the map.
     *
     * @param  array<int, string>  $openPeriods  periods the instructor still has open today
     * @return array<string, mixed>
     */
    private function transformInstructor(User $instructor, array $openPeriods = []): array
    {
        $profile = $instructor->instructorProfile;

        $level = $profile?->level;
        $levelEnum = $level instanceof InstructorLevel
            ? $level
            : ($level !== null ? InstructorLevel::tryFrom((int) $level) : null);

        return array_merge($instructor->toArray(), [
            'total_reviews' => (int) ($instructor->reviews_as_instructor_count ?? 0),
            'average_rating' => round((float) ($instructor->reviews_as_instructor_avg_rating ?? 0), 1),
            'avatar_url' => $instructor->avatar_url,
            'profile_url' => route('instructors.profile', $instructor->id),
            // Safety rules the directory is allowed to state out loud.
            'level_label' => $levelEnum?->shortLabel(),
            'max_students' => $levelEnum?->maxStudents(),
            'teaches_all_levels' => $levelEnum
                ? count($levelEnum->allowedSkillLevels()) === count(SkillLevel::cases())
                : null,
            'available_today' => $openPeriods !== [],
            'open_periods' => array_map(fn (string $period) => ucfirst($period), $openPeriods),
            'map' => [
                'latitude' => $profile?->latitude !== null ? (float) $profile->latitude : null,
                'longitude' => $profile?->longitude !== null ? (float) $profile->longitude : null,
                'area' => $profile?->area,
                'meeting_point' => $profile?->meeting_point,
            ],
        ]);
    }

    /**
     * Display single instructor profile
     */
    public function show(
        User $instructor,
        AvailabilityService $availabilityService,
        InstructorVerificationService $verification
    ): Response {
        // BR-2.1 / BR-1.2 - suspended, pending and unverified instructors are
        // hidden from public view. A shared profile link cannot bypass the
        // search filter.
        abort_unless(
            $instructor->isInstructor() && $verification->summarize($instructor)['can_book'],
            404
        );

        // A student may only open the profile of an instructor whose level can
        // actually teach them. Guests and admins are unaffected.
        $viewer = auth()->user();

        if ($viewer && $viewer->isStudent() && $viewer->studentProfile) {
            $viewerSkill = $viewer->studentProfile->skill_level;
            $viewerSkill = $viewerSkill instanceof SkillLevel
                ? $viewerSkill
                : SkillLevel::tryFrom((string) $viewerSkill);

            $profileLevel = $instructor->instructorProfile?->level;
            $profileLevelValue = $profileLevel instanceof InstructorLevel
                ? $profileLevel->value
                : (int) $profileLevel;

            abort_unless(
                $viewerSkill !== null && $viewerSkill->canBeTaughtBy($profileLevelValue),
                404
            );
        }

        $instructor->load([
            'instructorProfile',
            'certificates' => function ($q) {
                $q->where('status', 'verified');
            },
            'reviewsAsInstructor' => function ($q) {
                $q->visible()
                    ->with('student')
                    ->latest()
                    ->limit(10);
            },
            'availabilities' => function ($q) {
                $q->where('is_available', true)
                    ->where('specific_date', '>=', now()->format('Y-m-d'))
                    ->orWhereNull('specific_date');
            },
        ]);

        // Calculate average rating
        $averageRating = $instructor->reviewsAsInstructor()->visible()->avg('rating') ?? 0;
        $totalReviews = $instructor->reviewsAsInstructor()->visible()->count();

        return Inertia::render('Public/Instructors/Profile', [
            'instructor' => $instructor,
            'averageRating' => round($averageRating, 1),
            'totalReviews' => $totalReviews,
            // FR 1.8.2 - show the weekly schedule up front so students do not
            // have to guess which days this instructor actually works.
            'weeklyAvailability' => $availabilityService->weeklyPattern($instructor->id),
        ]);
    }
}
