<?php

namespace App\Policies;

use App\Models\SafetyIncident;
use App\Models\User;

class SafetyIncidentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Only admin and instructors can view incidents
        return $user->isAdmin() || $user->isInstructor();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SafetyIncident $safetyIncident): bool
    {
        // Instructor can view incidents where they are involved
        if ($user->id === $safetyIncident->instructor_id) {
            return true;
        }

        // Admin can view any incident
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
        // Only admin can create incidents
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SafetyIncident $safetyIncident): bool
    {
        // Only admin can update incidents
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can resolve the incident.
     */
    public function resolve(User $user, SafetyIncident $safetyIncident): bool
    {
        // Only admin can resolve incidents
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SafetyIncident $safetyIncident): bool
    {
        // Only admin can delete incidents
        return $user->isAdmin();
    }
}
