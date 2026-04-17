<?php

namespace App\Policies;

use App\Models\Certificate;
use App\Models\User;

class CertificatePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Certificate $certificate): bool
    {
        // User can view their own certificates
        if ($user->id === $certificate->user_id) {
            return true;
        }

        // Admin can view any certificate
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Instructors can upload their own certificates
        if ($user->isInstructor()) {
            return true;
        }

        // Admin can upload for any instructor
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Certificate $certificate): bool
    {
        // User can update their own pending certificates
        if ($user->id === $certificate->user_id && $certificate->isPending()) {
            return true;
        }

        // Admin can update any certificate (for verification)
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can verify the certificate.
     */
    public function verify(User $user, Certificate $certificate): bool
    {
        // Only admin can verify certificates
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Certificate $certificate): bool
    {
        // User can delete their own pending certificates
        if ($user->id === $certificate->user_id && $certificate->isPending()) {
            return true;
        }

        // Admin can delete any certificate
        return $user->isAdmin();
    }
}