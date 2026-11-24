<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\CertificateStatus;
use App\Enums\InstructorStatus;
use App\Enums\SkillLevel;
use App\Models\Booking;
use App\Models\InstructorProfile;
use App\Models\Review;
use App\Models\SurfSpot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LandingPageService
{
    /**
     * Build all dynamic props for the public landing page, falling back
     * seamlessly to curated static preview cards when database tables are empty.
     *
     * @return array<string, mixed>
     */
    public function payload(?User $viewer = null): array
    {
        $discoverableIds = $this->discoverableInstructorsQuery()
            ->pluck('users.id');

        return [
            'stats' => $this->buildStats($discoverableIds),
            'featuredSpots' => $this->buildFeaturedSpots(),
            'featuredInstructors' => $this->buildFeaturedInstructors($viewer),
            'recentReviews' => $this->buildRecentReviews(),
            'reviewStats' => $this->buildReviewStats(),
        ];
    }

    /**
     * Base query for BR-2.1 discoverable instructors (active, not suspended,
     * and holding at least one verified, non-expired certificate).
     */
    public function discoverableInstructorsQuery(): Builder
    {
        return User::query()
            ->with(['instructorProfile.surfSpots'])
            ->where('role', 'instructor')
            ->whereHas('instructorProfile', function (Builder $q): void {
                $q->where('status', InstructorStatus::Active->value)
                    ->where(function (Builder $subQ): void {
                        $subQ->whereNull('suspended_until')
                            ->orWhere('suspended_until', '<=', now());
                    });
            })
            ->whereHas('certificates', function (Builder $q): void {
                $q->where('status', CertificateStatus::Verified->value)
                    ->where(function (Builder $certificate): void {
                        $certificate->whereNull('expires_at')
                            ->orWhere('expires_at', '>=', now());
                    });
            });
    }

    /**
     * @param  Collection<int, int>  $discoverableIds
     * @return array<string, int>
     */
    private function buildStats(Collection $discoverableIds): array
    {
        $activeSpotsCount = SurfSpot::query()->active()->count();
        $beginnerSafeCount = SurfSpot::query()->active()->safeForBeginners()->count();
        $defaultBaseRate = (int) config('siasurf.services.teaching.min_rate', 600);

        $minRate = null;
        if ($discoverableIds->isNotEmpty()) {
            $minRate = InstructorProfile::query()
                ->whereIn('user_id', $discoverableIds->all())
                ->get()
                ->map(fn (InstructorProfile $profile): float => $profile->startingRate())
                ->filter(fn (float $rate): bool => $rate > 0)
                ->min();
        }

        return [
            'total_spots' => $activeSpotsCount > 0 ? $activeSpotsCount : 31,
            'beginner_safe_spots' => $activeSpotsCount > 0 ? $beginnerSafeCount : 14,
            'verified_instructors' => $discoverableIds->count(),
            'base_rate' => $minRate !== null ? (int) round((float) $minRate) : $defaultBaseRate,
            'completed_sessions' => Booking::query()
                ->where('status', BookingStatus::Completed->value)
                ->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFeaturedSpots(): array
    {
        $spots = SurfSpot::query()
            ->active()
            ->withCount('bookings')
            ->ordered()
            ->get();

        if ($spots->isEmpty()) {
            return $this->fallbackSpots();
        }

        $preferredNames = [
            SkillLevel::Advanced->value => ['Cloud 9 (Outer)', 'Cloud 9'],
            SkillLevel::Intermediate->value => ['Quick Silver (Outer)', 'Quicksilver'],
            SkillLevel::Beginner->value => ['Jacking Horse (Inner)', 'Jacking Horse'],
        ];

        $selected = collect();
        $selectedIds = [];

        foreach ([SkillLevel::Advanced->value, SkillLevel::Intermediate->value, SkillLevel::Beginner->value] as $difficulty) {
            $tierSpots = $spots->where('difficulty', $difficulty);
            if ($tierSpots->isEmpty()) {
                continue;
            }

            $iconic = $tierSpots->first(
                fn (SurfSpot $spot): bool => in_array($spot->name, $preferredNames[$difficulty], true)
            );

            $chosen = $iconic ?? $tierSpots
                ->sortBy([
                    ['bookings_count', 'desc'],
                    ['sort_order', 'asc'],
                    ['name', 'asc'],
                ])
                ->first();

            if ($chosen instanceof SurfSpot) {
                $selected->push($chosen);
                $selectedIds[] = $chosen->id;
            }
        }

        if ($selected->count() < 3) {
            $backfill = $spots
                ->reject(fn (SurfSpot $spot): bool => in_array($spot->id, $selectedIds, true))
                ->sortBy([
                    ['bookings_count', 'desc'],
                    ['sort_order', 'asc'],
                    ['name', 'asc'],
                ])
                ->take(3 - $selected->count());

            $selected = $selected->concat($backfill);
        }

        return $selected
            ->map(function (SurfSpot $spot): array {
                $images = $spot->images();
                $fallbackImage = match ($spot->difficulty) {
                    SkillLevel::Advanced->value => '/images/spot_cloud9.png',
                    SkillLevel::Intermediate->value => '/images/spot_quicksilver.png',
                    default => '/images/spot_jacking_horse.png',
                };

                return [
                    'id' => $spot->id,
                    'name' => $spot->name,
                    'area' => $spot->area,
                    'difficulty' => (string) $spot->difficulty,
                    'difficulty_label' => ucfirst((string) $spot->difficulty),
                    'description' => $spot->description ?: 'Mapped Siargao break with verified safety metadata and local tide guidance.',
                    'best_tide' => $spot->best_tide,
                    'image_url' => $images[0]['url'] ?? $fallbackImage,
                    'url' => route('surf-spots.index', ['difficulty' => $spot->difficulty]),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFeaturedInstructors(?User $viewer): array
    {
        $viewerSkill = null;
        if ($viewer && $viewer->isStudent() && $viewer->studentProfile) {
            $rawSkill = $viewer->studentProfile->skill_level;
            $viewerSkill = $rawSkill instanceof SkillLevel
                ? $rawSkill
                : ($rawSkill ? SkillLevel::tryFrom((string) $rawSkill) : null);
        }

        $baseQuery = $this->discoverableInstructorsQuery()
            ->withAvg(['reviewsAsInstructor' => fn ($reviews) => $reviews->visible()], 'rating')
            ->withCount(['reviewsAsInstructor' => fn ($reviews) => $reviews->visible()])
            ->orderByDesc('reviews_as_instructor_avg_rating')
            ->orderByDesc('reviews_as_instructor_count')
            ->orderByDesc(
                InstructorProfile::query()
                    ->select('level')
                    ->whereColumn('instructor_profiles.user_id', 'users.id')
                    ->limit(1)
            )
            ->orderByDesc('users.created_at');

        $instructors = collect();

        if ($viewerSkill !== null) {
            $instructors = (clone $baseQuery)
                ->whereHas('instructorProfile', function (Builder $q) use ($viewerSkill): void {
                    $q->whereIn('level', $viewerSkill->instructorLevels());
                })
                ->limit(2)
                ->get();
        }

        if ($instructors->isEmpty()) {
            $instructors = (clone $baseQuery)
                ->limit(2)
                ->get();
        }

        if ($instructors->isEmpty()) {
            return $this->fallbackInstructors();
        }

        return $instructors
            ->values()
            ->map(function (User $instructor, int $index) use ($viewerSkill): array {
                $profile = $instructor->instructorProfile;
                $levelValue = (int) ($profile?->level?->value ?? 1);
                $totalReviews = (int) ($instructor->reviews_as_instructor_count ?? 0);
                $avgRating = $instructor->reviews_as_instructor_avg_rating;

                $canOpenProfile = $viewerSkill === null
                    || $viewerSkill->canBeTaughtBy($levelValue);

                $levelBadge = match ($levelValue) {
                    3 => 'Level 3 Expert',
                    2 => 'Level 2 Pro',
                    default => 'Level 1 Certified',
                };

                return [
                    'id' => $instructor->id,
                    'name' => $instructor->name,
                    'level' => $levelValue,
                    'level_badge' => $levelBadge,
                    'bio' => $profile?->bio ?: 'SISA-verified Siargao surf instructor committed to safe, level-matched sessions.',
                    'area' => $profile?->area ?: 'Siargao Island',
                    'average_rating' => $avgRating !== null ? round((float) $avgRating, 1) : null,
                    'total_reviews' => $totalReviews,
                    'show_stars' => $totalReviews > 0,
                    'starting_rate' => $profile ? (int) round($profile->startingRate()) : null,
                    'avatar_url' => $instructor->avatar_url ?: ($index % 2 === 0 ? '/images/instructor_male.png' : '/images/instructor_female.png'),
                    'profile_url' => $canOpenProfile
                        ? route('instructors.profile', $instructor->id)
                        : route('instructors.search'),
                    'cta_label' => $canOpenProfile
                        ? 'View Verified Profile'
                        : 'Browse Verified Instructors',
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentReviews(): array
    {
        $reviews = Review::query()
            ->visible()
            ->with([
                'student:id,name,avatar_path',
                'instructor:id,name',
                'booking:id,surf_spot_id,date',
                'booking.surfSpot:id,name',
            ])
            ->whereNotNull('comment')
            ->where('comment', '!=', '')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        if ($reviews->isEmpty()) {
            return $this->fallbackReviews();
        }

        return $reviews
            ->map(fn (Review $review): array => [
                'id' => $review->id,
                'rating' => (int) ($review->rating ?? 5),
                'comment' => $review->comment,
                'student_name' => $review->student?->name ?? 'Verified Surfer',
                'student_avatar_url' => $review->student?->avatar_url,
                'instructor_name' => $review->instructor?->name ?? 'SISA Instructor',
                'surf_spot' => $review->booking?->surfSpot?->name,
                'created_at' => $review->created_at?->format('M d, Y') ?? '',
                'photo_url' => $review->photo_path ? '/storage/'.ltrim($review->photo_path, '/') : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{average_rating: float|null, total_reviews: int}
     */
    private function buildReviewStats(): array
    {
        $totalReviews = Review::query()->visible()->count();

        if ($totalReviews === 0) {
            return [
                'average_rating' => 5.0,
                'total_reviews' => 0,
            ];
        }

        $average = Review::query()
            ->visible()
            ->whereNotNull('rating')
            ->avg('rating');

        return [
            'average_rating' => $average !== null ? round((float) $average, 1) : null,
            'total_reviews' => $totalReviews,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fallbackSpots(): array
    {
        return [
            [
                'id' => 'fallback-cloud9',
                'name' => 'Cloud 9',
                'area' => 'General Luna',
                'difficulty' => 'advanced',
                'difficulty_label' => 'Advanced',
                'description' => "The island's most famous break. Thick, hollow tubes at mid-to-high tide.",
                'best_tide' => 'Mid to high',
                'image_url' => '/images/spot_cloud9.png',
                'url' => route('surf-spots.index'),
            ],
            [
                'id' => 'fallback-quicksilver',
                'name' => 'Quicksilver',
                'area' => 'General Luna',
                'difficulty' => 'intermediate',
                'difficulty_label' => 'Intermediate',
                'description' => 'Right next to Cloud 9. Offers faster, sharper waves perfect for refining your skills.',
                'best_tide' => null,
                'image_url' => '/images/spot_quicksilver.png',
                'url' => route('surf-spots.index'),
            ],
            [
                'id' => 'fallback-jacking-horse',
                'name' => 'Jacking Horse',
                'area' => 'General Luna',
                'difficulty' => 'beginner',
                'difficulty_label' => 'Beginner',
                'description' => 'A friendly, rolling break ideal for first-timers and those learning the basics.',
                'best_tide' => null,
                'image_url' => '/images/spot_jacking_horse.png',
                'url' => route('surf-spots.index'),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fallbackInstructors(): array
    {
        return [
            [
                'id' => 'fallback-junrey',
                'name' => "Junrey 'Bogs' Capistrano",
                'level' => 3,
                'level_badge' => 'Level 3 Expert',
                'bio' => 'Local legend with 15 years at Cloud 9. Specializes in advanced tube riding and safety rescue.',
                'area' => 'Cloud 9, General Luna',
                'average_rating' => 5.0,
                'total_reviews' => 0,
                'show_stars' => true,
                'starting_rate' => null,
                'avatar_url' => '/images/instructor_male.png',
                'profile_url' => route('instructors.search'),
                'cta_label' => 'Browse Verified Instructors',
            ],
            [
                'id' => 'fallback-ana',
                'name' => "Ana 'Maui' San Jose",
                'level' => 2,
                'level_badge' => 'Level 2 Pro',
                'bio' => "ISA certified instructor focusing on intermediate women's surf camps and longboarding style.",
                'area' => 'General Luna',
                'average_rating' => 5.0,
                'total_reviews' => 0,
                'show_stars' => true,
                'starting_rate' => null,
                'avatar_url' => '/images/instructor_female.png',
                'profile_url' => route('instructors.search'),
                'cta_label' => 'Browse Verified Instructors',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fallbackReviews(): array
    {
        return [
            [
                'id' => 'fallback-review-1',
                'rating' => 5,
                'comment' => 'First time on a surfboard and I stood up on my third wave. Scanning the SISA QR code on the beach gave me complete peace of mind.',
                'student_name' => 'Camille Reyes',
                'student_avatar_url' => null,
                'instructor_name' => "Junrey 'Bogs' Capistrano",
                'surf_spot' => 'Jacking Horse',
                'created_at' => 'Verified Session',
                'photo_url' => null,
            ],
            [
                'id' => 'fallback-review-2',
                'rating' => 5,
                'comment' => 'Clear wave-reading tips, locked-in SISA pricing, and smooth GCash checkout. Easily the safest and best-organized surf session I booked in Siargao.',
                'student_name' => 'Liam Carter',
                'student_avatar_url' => null,
                'instructor_name' => "Ana 'Maui' San Jose",
                'surf_spot' => 'Quicksilver',
                'created_at' => 'Verified Session',
                'photo_url' => null,
            ],
            [
                'id' => 'fallback-review-3',
                'rating' => 5,
                'comment' => 'Booked a Level 3 guide for an early morning outer-reef session. Great local tide knowledge and super professional safety briefing from start to finish.',
                'student_name' => 'Mikaela Santos',
                'student_avatar_url' => null,
                'instructor_name' => "Junrey 'Bogs' Capistrano",
                'surf_spot' => 'Cloud 9',
                'created_at' => 'Verified Session',
                'photo_url' => null,
            ],
        ];
    }
}
