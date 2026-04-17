<?php

namespace App\Policies;

use App\Models\InstructorProfile;
use App\Models\User;

class InstructorProfilePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // Anyone can view instructor profiles
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, InstructorProfile $instructorProfile): bool
    {
        // Anyone can view active instructor profiles
        if ($instructorProfile->isActive()) {
            return true;
        }

        // Instructor can view their own profile
        if ($user->id === $instructorProfile->user_id) {
            return true;
        }

        // Admin can view any profile
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
        // Admin can create instructor profiles
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, InstructorProfile $instructorProfile): bool
    {
        // Instructor can update their own profile
        if ($user->id === $instructorProfile->user_id) {
            return true;
        }

        // Admin can update any profile
        if ($user->isAdmin()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can verify the instructor.
     */
    public function verify(User $user): bool
    {
        // Only admin can verify instructors
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can suspend the instructor.
     */
    public function suspend(User $user, InstructorProfile $instructorProfile): bool
    {
        // Only admin can suspend instructors
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, InstructorProfile $instructorProfile): bool
    {
        // Only admin can delete instructor profiles
        return $user->isAdmin();
    }
}
