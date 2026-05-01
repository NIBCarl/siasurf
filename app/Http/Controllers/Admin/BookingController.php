<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    /**
     * Display a listing of all bookings for admin.
     */
    public function index(Request $request): Response
    {
        $query = Booking::with(['student', 'instructor', 'surfSpot'])
            ->orderByDesc('created_at');

        // Simple status filter
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $bookings = $query->paginate(20)->withQueryString();

        return Inertia::render('Admin/Bookings/Index', [
            'bookings' => $bookings,
            'filters' => $request->only(['status']),
        ]);
    }

    /**
     * Display the specified booking details.
     */
    public function show(Booking $booking): Response
    {
        $booking->load(['student', 'instructor', 'surfSpot', 'payment', 'waiver', 'review']);

        return Inertia::render('Admin/Bookings/Show', [
            'booking' => $booking,
        ]);
    }
}
