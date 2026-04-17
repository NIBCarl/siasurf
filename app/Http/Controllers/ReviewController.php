<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReviewRequest;
use App\Http\Requests\UpdateReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Booking;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    /**
     * Display a listing of the student's reviews.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        
        // Past reviews given by the student
        $reviews = Review::where('student_id', $user->id)
            ->with(['instructor', 'booking'])
            ->orderBy('created_at', 'desc')
            ->get();
            
        // Bookings that are completed but don't have a review yet
        $pendingReviews = Booking::where('student_id', $user->id)
            ->where('status', 'completed')
            ->whereDoesntHave('review')
            ->with('instructor')
            ->get();

        return Inertia::render('Student/Reviews/Index', [
            'reviews' => $reviews,
            'pendingReviews' => $pendingReviews,
        ]);
    }

    /**
     * Display reviews for an instructor
     */
    public function forInstructor(User $instructor, Request $request)
    {
        $query = Review::forInstructor($instructor->id)
            ->with(['student', 'booking'])
            ->orderBy('created_at', 'desc');

        // Filter by rating
        if ($request->has('rating')) {
            $query->withRating($request->integer('rating'));
        }

        // Filter reviews with photos
        if ($request->boolean('with_photos')) {
            $query->withPhoto();
        }

        $reviews = $query->paginate(10);

        return response()->json([
            'reviews' => ReviewResource::collection($reviews),
            'pagination' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
            ],
            'stats' => $this->getInstructorReviewStats($instructor->id),
        ]);
    }

    /**
     * Get review statistics for an instructor
     */
    private function getInstructorReviewStats(int $instructorId): array
    {
        $reviews = Review::forInstructor($instructorId)->get();

        if ($reviews->isEmpty()) {
            return [
                'average_rating' => 0,
                'total_reviews' => 0,
                'rating_distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
                'response_rate' => 0,
            ];
        }

        $total = $reviews->count();
        $average = round($reviews->avg('rating'), 1);

        $distribution = [
            5 => $reviews->where('rating', 5)->count(),
            4 => $reviews->where('rating', 4)->count(),
            3 => $reviews->where('rating', 3)->count(),
            2 => $reviews->where('rating', 2)->count(),
            1 => $reviews->where('rating', 1)->count(),
        ];

        $responseRate = round(($reviews->whereNotNull('instructor_response')->count() / $total) * 100);

        return [
            'average_rating' => $average,
            'total_reviews' => $total,
            'rating_distribution' => $distribution,
            'response_rate' => $responseRate,
        ];
    }

    /**
     * Show the form for creating a review
     */
    public function create(Booking $booking): Response
    {
        $this->authorize('createForBooking', [Review::class, $booking]);

        return Inertia::render('Student/Reviews/Create', [
            'booking' => $booking->load(['instructor', 'surfSpot']),
        ]);
    }

    /**
     * Store a newly created review
     */
    public function store(StoreReviewRequest $request, Booking $booking)
    {
        $this->authorize('createForBooking', [Review::class, $booking]);

        $validated = $request->validated();

        $review = Review::create([
            'booking_id' => $booking->id,
            'student_id' => $request->user()->id,
            'instructor_id' => $booking->instructor_id,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'],
        ]);

        // Handle photo upload
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('reviews', 'local');
            $review->update(['photo_path' => $photoPath]);
        }

        return redirect()->route('student.bookings.show', $booking)
            ->with('success', 'Thank you for your review!');
    }

    /**
     * Display the specified review
     */
    public function show(Review $review)
    {
        $review->load(['student', 'instructor', 'booking']);

        return response()->json([
            'review' => new ReviewResource($review),
        ]);
    }

    /**
     * Show the form for editing the review
     */
    public function edit(Review $review): Response
    {
        $this->authorize('update', $review);

        return Inertia::render('Student/Reviews/Edit', [
            'review' => $review->load(['booking.instructor', 'booking.surfSpot']),
        ]);
    }

    /**
     * Update the review
     */
    public function update(UpdateReviewRequest $request, Review $review)
    {
        $this->authorize('update', $review);

        $validated = $request->validated();

        $review->edit($validated['comment'], $validated['rating']);

        // Handle photo update
        if ($request->hasFile('photo')) {
            // Delete old photo
            if ($review->photo_path) {
                Storage::disk('local')->delete($review->photo_path);
            }

            $photoPath = $request->file('photo')->store('reviews', 'local');
            $review->update(['photo_path' => $photoPath]);
        }

        return redirect()->route('student.bookings.show', $review->booking)
            ->with('success', 'Review updated successfully.');
    }

    /**
     * Instructor responds to a review
     */
    public function respond(Request $request, Review $review)
    {
        $this->authorize('respond', $review);

        $validated = $request->validate([
            'response' => 'required|string|max:1000',
        ]);

        $review->respond($validated['response']);

        return redirect()->back()
            ->with('success', 'Response posted successfully.');
    }

    /**
     * Remove the review photo
     */
    public function removePhoto(Review $review)
    {
        $this->authorize('update', $review);

        if ($review->photo_path) {
            Storage::disk('local')->delete($review->photo_path);
            $review->update(['photo_path' => null]);
        }

        return redirect()->back()
            ->with('success', 'Photo removed successfully.');
    }

    /**
     * Admin moderation - hide review
     */
    public function moderate(Request $request, Review $review)
    {
        $this->authorize('moderate', $review);

        $validated = $request->validate([
            'action' => 'required|in:hide,unhide,delete',
            'reason' => 'required_if:action,hide|nullable|string|max:500',
        ]);

        switch ($validated['action']) {
            case 'hide':
                $review->update([
                    'is_hidden' => true,
                    'moderation_reason' => $validated['reason'],
                    'moderated_at' => now(),
                    'moderated_by' => $request->user()->id,
                ]);
                $message = 'Review has been hidden.';
                break;

            case 'unhide':
                $review->update([
                    'is_hidden' => false,
                    'moderation_reason' => null,
                    'moderated_at' => null,
                    'moderated_by' => null,
                ]);
                $message = 'Review has been unhidden.';
                break;

            case 'delete':
                // Delete photo if exists
                if ($review->photo_path) {
                    Storage::disk('local')->delete($review->photo_path);
                }
                $review->delete();
                $message = 'Review has been deleted.';
                break;
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * Get recent reviews for dashboard
     */
    public function recent(Request $request)
    {
        $limit = $request->integer('limit', 5);

        $reviews = Review::with(['student', 'instructor', 'booking'])
            ->visible()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'reviews' => ReviewResource::collection($reviews),
        ]);
    }
}
