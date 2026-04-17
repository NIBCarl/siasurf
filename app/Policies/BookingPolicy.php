<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Booking $booking): bool
    {
        // Student can view their own bookings
        if ($user->id === $booking->student_id) {
            return true;
        }

        // Instructor can view bookings assigned to them
        if ($user->id === $booking->instructor_id) {
            return true;
        }

        // Admin can view any booking
        if ($user->isAdmin()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Only students can create bookings
        return $user->isStudent();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Booking $booking): bool
    {
        // Student can update their own pending/confirmed bookings
        if ($user->id === $booking->student_id && ($booking->isPending() || $booking->isConfirmed())) {
            return true;
        }

        // Instructor can confirm/cancel their assigned bookings
        if ($user->id === $booking->instructor_id) {
            return true;
        }

        // Admin can update any booking
        if ($user->isAdmin()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can cancel the booking.
     */
    public function cancel(User $user, Booking $booking): bool
    {
        // Student can cancel their own booking if it's pending or confirmed
        if ($user->id === $booking->student_id && $booking->canBeCancelled()) {
            return true;
        }

        // Instructor can cancel bookings assigned to them
        if ($user->id === $booking->instructor_id) {
            return true;
        }

        // Admin can cancel any booking
        if ($user->isAdmin()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can confirm the booking.
     */
    public function confirm(User $user, Booking $booking): bool
    {
        // Only instructor or admin can confirm
        if ($user->id === $booking->instructor_id || $user->isAdmin()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can complete the booking.
     */
    public function complete(User $user, Booking $booking): bool
    {
        // Only instructor or admin can mark as completed
        if ($user->id === $booking->instructor_id || $user->isAdmin()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Booking $booking): bool
    {
        // Only admin can delete bookings (soft delete)
        return $user->isAdmin();
    }
}
