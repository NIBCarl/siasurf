<?php

namespace App\Policies;

use App\Models\Refund;
use App\Models\User;

class RefundPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Refund $refund): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $booking = $refund->booking;

        return $booking !== null
            && ($user->id === $booking->student_id || $user->id === $booking->instructor_id);
    }

    public function retry(User $user, Refund $refund): bool
    {
        return $user->isAdmin();
    }

    public function completeManual(User $user, Refund $refund): bool
    {
        return $user->isAdmin();
    }

    /**
     * BR-PAY-05: Refund records are immutable.
     */
    public function delete(User $user, Refund $refund): bool
    {
        return false;
    }
}
