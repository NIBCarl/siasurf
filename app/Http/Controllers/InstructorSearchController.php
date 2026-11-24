<?php

namespace App\Http\Controllers;

use App\Enums\CertificateStatus;
use App\Enums\InstructorLevel;
use App\Enums\InstructorStatus;
use App\Enums\SessionType;
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
        $query = User::with('instructorProfile.surfSpots')
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

        // Skill Level Matching - show only the instructors whose certification,
        // offered lesson types, and configured surf zones cover this student's level:
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
            $bookableValues = array_map(
                fn (SessionType $service): string => $service->value,
                SessionType::bookableBy($skillLevel)
            );
            $allowedDifficulties = $skillLevel->allowedSurfSpotDifficulties();

            $query->whereHas('instructorProfile', function ($q) use ($allowedInstructorLevels, $bookableValues, $allowedDifficulties) {
                $q->whereIn('level', $allowedInstructorLevels)
                    ->where(function ($serviceQ) use ($bookableValues) {
                        $serviceQ->whereNull('offered_services');
                        foreach ($bookableValues as $serviceValue) {
                            $serviceQ->orWhereJsonContains('offered_services', $serviceValue);
                        }
                    })
                    ->where(function ($spotQ) use ($allowedDifficulties) {
                        $spotQ->whereDoesntHave('surfSpots')
                            ->orWhereHas('surfSpots', function ($spot) use ($allowedDifficulties) {
                                $spot->where('is_active', true)
                                    ->whereIn('difficulty', $allowedDifficulties);
                            });
                    });
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

        // Applicable service for price display/filtering/sorting:
        // Beginner -> Teaching (rate_per_hour), Intermediate -> Guiding, Advanced -> Coaching.
        // Fall back across other rate columns when an instructor opted out of Teaching.
        $viewerService = $skillLevel ? SessionType::recommendedFor($skillLevel, 3) : null;
        $priceExpr = match ($viewerService) {
            SessionType::Guiding => 'COALESCE(guiding_rate, coaching_rate, rate_per_hour, '.SessionType::Guiding->defaultRate().')',
            SessionType::Coaching => 'COALESCE(coaching_rate, guiding_rate, rate_per_hour, '.SessionType::Coaching->defaultRate().')',
            default => 'COALESCE(rate_per_hour, guiding_rate, coaching_rate, '.SessionType::Teaching->defaultRate().')',
        };
        $qualifiedPriceExpr = match ($viewerService) {
            SessionType::Guiding => 'COALESCE(instructor_profiles.guiding_rate, instructor_profiles.coaching_rate, instructor_profiles.rate_per_hour, '.SessionType::Guiding->defaultRate().')',
            SessionType::Coaching => 'COALESCE(instructor_profiles.coaching_rate, instructor_profiles.guiding_rate, instructor_profiles.rate_per_hour, '.SessionType::Coaching->defaultRate().')',
            default => 'COALESCE(instructor_profiles.rate_per_hour, instructor_profiles.guiding_rate, instructor_profiles.coaching_rate, '.SessionType::Teaching->defaultRate().')',
        };

        // Filter by price range (against the service this student actually books)
        if ($request->filled('min_price')) {
            $query->whereHas('instructorProfile', function ($q) use ($request, $priceExpr) {
                $q->whereRaw("{$priceExpr} >= ?", [(float) $request->min_price]);
            });
        }
        if ($request->filled('max_price')) {
            $query->whereHas('instructorProfile', function ($q) use ($request, $priceExpr) {
                $q->whereRaw("{$priceExpr} <= ?", [(float) $request->max_price]);
            });
        }

        // Directory location filter. Matches the instructor's base area or any
        // active surf zone they selected in that municipality.
        if ($request->filled('area')) {
            $areaFilter = $request->area;
            $query->whereHas('instructorProfile', function ($q) use ($areaFilter) {
                $q->where('area', $areaFilter)
                    ->orWhereHas('surfSpots', function ($spotQ) use ($areaFilter) {
                        $spotQ->where('is_active', true)->where('area', $areaFilter);
                    });
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
                    ->orderByRaw("{$qualifiedPriceExpr} asc")
                    ->select('users.*');
                break;
            case 'price_high':
                $query->join('instructor_profiles', 'users.id', '=', 'instructor_profiles.user_id')
                    ->orderByRaw("{$qualifiedPriceExpr} desc")
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
            ->map(fn (User $instructor) => $this->transformInstructor($instructor, [], $viewerService, $skillLevel))
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
            $viewerService,
            $skillLevel,
        ));

        // Get surf spots for filtering
        $surfSpots = SurfSpot::where('is_active', true)
            ->select('id', 'name', 'difficulty')
            ->get();

        $profileAreas = InstructorProfile::query()
            ->whereNotNull('area')
            ->where('area', '!=', '')
            ->pluck('area');

        $spotAreas = SurfSpot::query()
            ->active()
            ->whereNotNull('area')
            ->where('area', '!=', '')
            ->whereHas('instructorProfiles', fn ($q) => $q->where('status', InstructorStatus::Active->value))
            ->pluck('area');

        $areas = $profileAreas
            ->merge($spotAreas)
            ->unique()
            ->sort()
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
    private function transformInstructor(
        User $instructor,
        array $openPeriods = [],
        ?SessionType $viewerService = null,
        ?SkillLevel $skillLevel = null
    ): array {
        $profile = $instructor->instructorProfile;

        $level = $profile?->level;
        $levelEnum = $level instanceof InstructorLevel
            ? $level
            : ($level !== null ? InstructorLevel::tryFrom((int) $level) : null);

        $activeService = ($skillLevel ? $profile?->recommendedServiceFor($skillLevel) : null)
            ?? ($profile?->offeredServices()[0] ?? $viewerService ?? SessionType::Teaching);
        $displayRate = $profile ? $profile->rateForService($activeService) : 600.0;

        $selectedSpots = $profile
            ? $profile->activeAllowedSurfSpots()
                ->map(fn (SurfSpot $spot): array => [
                    'id' => $spot->id,
                    'name' => $spot->name,
                    'area' => $spot->area,
                    'difficulty' => $spot->difficulty,
                ])
                ->values()
                ->all()
            : [];

        $publicInstructor = collect($instructor->toArray())
            ->except(['email', 'phone', 'role', 'email_verified_at', 'remember_token'])
            ->all();

        return array_merge($publicInstructor, [
            'total_reviews' => (int) ($instructor->reviews_as_instructor_count ?? 0),
            'average_rating' => round((float) ($instructor->reviews_as_instructor_avg_rating ?? 0), 1),
            'avatar_url' => $instructor->avatar_url,
            'profile_url' => route('instructors.profile', $instructor->id),
            // Safety rules the directory is allowed to state out loud.
            'level_label' => $levelEnum?->shortLabel(),
            'max_students' => $levelEnum?->maxStudents(),
            'display_rate' => $displayRate,
            'display_service_label' => $activeService->label(),
            'service_rates' => $profile ? $profile->serviceRates() : [],
            'offered_services' => $profile ? $profile->offeredServiceValues() : [],
            'surf_spots' => $selectedSpots,
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
        $viewerSkill = null;

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
            'instructorProfile.surfSpots',
            'certificates' => function ($q) {
                $q->where('status', 'verified');
            },
            'reviewsAsInstructor' => function ($q) {
                $q->visible()
                    ->with('student:id,name,avatar_path')
                    ->latest()
                    ->limit(10);
            },
            'availabilities' => function ($q) {
                $q->where('is_available', true)
                    ->where(function ($dateQuery) {
                        $dateQuery->where('specific_date', '>=', now()->format('Y-m-d'))
                            ->orWhereNull('specific_date');
                    });
            },
        ]);

        $instructor->makeHidden(['email', 'phone', 'role', 'email_verified_at', 'remember_token']);
        $instructor->reviewsAsInstructor->each(function ($review) {
            $review->student?->makeHidden(['email', 'phone', 'role', 'email_verified_at', 'remember_token']);
        });

        // Calculate average rating
        $averageRating = $instructor->reviewsAsInstructor()->visible()->avg('rating') ?? 0;
        $totalReviews = $instructor->reviewsAsInstructor()->visible()->count();

        $profile = $instructor->instructorProfile;
        $studentBookingBlockReason = null;

        if ($viewerSkill !== null && $profile !== null) {
            if ($profile->serviceOptionsFor($viewerSkill) === []) {
                $studentBookingBlockReason = 'This instructor does not currently offer a lesson type available for your '.$viewerSkill->label().' skill level.';
            } elseif ($profile->bookableSurfSpotsFor($viewerSkill)->isEmpty()) {
                $studentBookingBlockReason = 'This instructor does not currently teach in any surf zones permitted for your '.$viewerSkill->label().' skill level.';
            }
        }

        return Inertia::render('Public/Instructors/Profile', [
            'instructor' => $instructor,
            'serviceRates' => $profile?->serviceRates() ?? [],
            'startingRate' => $profile?->startingRate() ?? 0,
            'surfSpots' => $profile?->activeAllowedSurfSpots()
                ->map(fn (SurfSpot $spot): array => $spot->toMapPayload())
                ->values()
                ->all() ?? [],
            'studentBookingBlockReason' => $studentBookingBlockReason,
            'averageRating' => round($averageRating, 1),
            'totalReviews' => $totalReviews,
            // FR 1.8.2 - show the weekly schedule up front so students do not
            // have to guess which days this instructor actually works.
            'weeklyAvailability' => $availabilityService->weeklyPattern($instructor->id),
            'instructorSessionWarning' => $availabilityService->activeSessionNotice($instructor),
        ]);
    }
}
