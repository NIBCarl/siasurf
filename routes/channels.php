<?php

use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Student private channel - only the student can listen
Broadcast::channel('student.{id}', function (User $user, int $id) {
    return $user->id === $id && $user->isStudent();
});

// Instructor private channel - only the instructor can listen
Broadcast::channel('instructor.{id}', function (User $user, int $id) {
    return $user->id === $id && $user->isInstructor();
});

// Admin notifications channel - only admins can listen
Broadcast::channel('admin.notifications', function (User $user) {
    return $user->isAdmin();
});

// Private booking chat - only the student and instructor on that booking.
Broadcast::channel('booking.{bookingId}', function (User $user, int $bookingId) {
    $booking = Booking::find($bookingId);

    return $booking !== null
        && in_array($user->id, [$booking->student_id, $booking->instructor_id], true);
});
