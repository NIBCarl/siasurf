<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Admin can view all payments
        if ($user->isAdmin()) {
            return true;
        }

        // Instructor can view payments for their bookings
        if ($user->isInstructor()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Payment $payment): bool
    {
        // Student can view their own payments
        if ($user->id === $payment->booking->student_id) {
            return true;
        }

        // Instructor can view payments for their bookings
        if ($user->id === $payment->booking->instructor_id) {
            return true;
        }

        // Admin can view any payment
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
        // Payments are created automatically by system
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Payment $payment): bool
    {
        // Only admin can update payments
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can process refund.
     */
    public function refund(User $user, Payment $payment): bool
    {
        // Only admin can process refunds
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Payment $payment): bool
    {
        // Only admin can delete payments
        return $user->isAdmin();
    }
}
