<?php

namespace App\Policies;

use App\Models\InstructorPayout;
use App\Models\User;

class InstructorPayoutPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, InstructorPayout $payout): bool
    {
        return $user->isAdmin() || $user->id === $payout->instructor_id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, InstructorPayout $payout): bool
    {
        return false;
    }
}
