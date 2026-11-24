<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\InstructorVerificationService;
use App\Services\SisaIdCardService;
use Inertia\Inertia;
use Inertia\Response;

class InstructorVerificationController extends Controller
{
    /**
     * Live verification page opened by scanning an instructor's beach QR.
     */
    public function show(
        User $instructor,
        InstructorVerificationService $verification,
        SisaIdCardService $idCardService,
        AvailabilityService $availabilityService
    ): Response {
        abort_unless($instructor->isInstructor(), 404);

        $instructor->load(['instructorProfile']);

        $reviewsQuery = $instructor->reviewsAsInstructor()->visible();
        $reviewsCount = (clone $reviewsQuery)->count();
        $averageRating = (clone $reviewsQuery)->whereNotNull('rating')->avg('rating');
        $reviews = (clone $reviewsQuery)
            ->with('student:id,name')
            ->latest()
            ->limit(5)
            ->get();

        $summary = $verification->summarize($instructor);
        $profile = $instructor->instructorProfile;

        return Inertia::render('Public/WalkIn/Scan', [
            'instructor' => [
                'id' => $instructor->id,
                'name' => $instructor->name,
                'avatar_path' => $instructor->avatar_path,
                'avatar_url' => $instructor->avatar_url,
                'bio' => $profile?->bio,
                'level' => $profile?->level?->value,
                'level_label' => $profile?->level?->shortLabel(),
                'rate_per_hour' => $profile ? $profile->startingRate() : 0,
                'average_rating' => round((float) $averageRating, 1),
                'reviews_count' => $reviewsCount,
            ],
            'verification' => $summary,
            'idCard' => $idCardService->build($instructor),
            'instructorSessionWarning' => $summary['can_book']
                ? $availabilityService->activeSessionNotice($instructor)
                : null,
            'recentReviews' => $reviews->map(fn ($review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'student_name' => $review->student?->name ?? 'Surfer',
            ])->values(),
        ]);
    }
}
