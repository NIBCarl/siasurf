<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // Anyone can view reviews
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Review $review): bool
    {
        return true; // Anyone can view individual reviews
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Only students can create reviews
        return $user->isStudent();
    }

    /**
     * Determine whether the user can create a review for a specific booking.
     */
    public function createForBooking(User $user, $booking): bool
    {
        // Student must be the one who made the booking
        if ($user->id !== $booking->student_id) {
            return false;
        }

        // Booking must be completed
        if (!$booking->isCompleted()) {
            return false;
        }

        // Must not have already reviewed this booking
        if ($booking->review) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Review $review): bool
    {
        // Student can update their own review within 24 hours
        if ($user->id === $review->student_id && !$review->isEdited()) {
            return true;
        }

        // Admin can update any review
        if ($user->isAdmin()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can respond to the review.
     */
    public function respond(User $user, Review $review): bool
    {
        // Instructor can respond to reviews about them
        if ($user->id === $review->instructor_id) {
            return true;
        }

        // Admin can respond to any review
        if ($user->isAdmin()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can moderate the review.
     */
    public function moderate(User $user, Review $review): bool
    {
        // Only admin can moderate reviews
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Review $review): bool
    {
        // Admin can delete any review
        return $user->isAdmin();
    }
}
