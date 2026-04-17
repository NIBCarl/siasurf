<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Enums\BookingStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $user = auth()->user();

        // 1. Active Session (Currently ongoing)
        $activeSession = Booking::with(['instructor', 'surfSpot'])
            ->where('student_id', $user->id)
            ->where('status', BookingStatus::InProgress)
            ->whereNotNull('started_at')
            ->whereNull('completed_at')
            ->first();

        // 2. Upcoming Bookings
        $upcomingBookings = Booking::with(['instructor', 'surfSpot'])
            ->where('student_id', $user->id)
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed])
            ->whereNull('started_at')
            ->whereDate('date', '>=', now())
            ->orderBy('date', 'asc')
            ->orderBy('time_period', 'asc')
            ->take(4)
            ->get();

        // 3. Past Instructors (for Quick Re-book)
        $pastInstructors = Booking::with(['instructor.instructorProfile'])
            ->where('student_id', $user->id)
            ->where('status', BookingStatus::Completed)
            ->latest()
            ->get()
            ->unique('instructor_id')
            ->take(3)
            ->map(fn($booking) => $booking->instructor);

        // 4. Mock Surf & Weather Data for Siargao
        $surfReport = [
            'spot' => 'Cloud 9',
            'height' => '3-4ft',
            'condition' => 'Clean / Offshore',
            'wind' => '5kts SW',
            'tide' => 'High @ 10:45 AM',
            'temp' => '29°C',
            'icon' => 'wave'
        ];

        return Inertia::render('Student/Dashboard', [
            'activeSession' => $activeSession,
            'upcomingBookings' => $upcomingBookings,
            'pastInstructors' => $pastInstructors,
            'surfReport' => $surfReport,
        ]);
    }
}
