<?php

namespace App\Notifications\Concerns;

use App\Models\User;

/**
 * Resolves the page a notification should open for a given recipient.
 *
 * The recipient role decides the destination: the same booking belongs to a
 * student, an instructor and (for oversight) an admin, but each of them has a
 * different detail screen and a different authorization policy. Resolving this
 * server-side means the client never has to guess a URL, and a notification
 * can never link a user to a page they cannot open.
 */
trait ResolvesNotificationUrl
{
    /**
     * The booking detail route that matches the recipient's role.
     */
    protected function bookingUrl(User $user, ?int $bookingId): ?string
    {
        if ($bookingId === null) {
            return null;
        }

        return match ($user->role) {
            'student' => route('student.bookings.show', $bookingId),
            'instructor' => route('instructor.bookings.show', $bookingId),
            'admin' => route('admin.bookings.show', $bookingId),
            default => null,
        };
    }

    /**
     * Fall back to the recipient's own console when a specific record is not
     * something we can safely deep-link.
     */
    protected function dashboardUrl(User $user): string
    {
        return match ($user->role) {
            'admin' => route('admin.dashboard'),
            'instructor' => route('instructor.dashboard'),
            default => route('student.dashboard'),
        };
    }
}
