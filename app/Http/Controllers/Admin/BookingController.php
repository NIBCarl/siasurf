<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Services\BookingChangeService;
use Illuminate\Http\RedirectResponse;
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
            'pendingChangeRequestCount' => BookingChangeRequest::query()->awaitingAdmin()->count(),
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

    /**
     * FR 1.9.4 - late instructor cancellations and reschedules land here.
     */
    public function changeRequests(Request $request): Response
    {
        $status = $request->query('status', 'pending');

        $changeRequests = BookingChangeRequest::with([
                'booking.student',
                'booking.instructor',
                'booking.surfSpot',
                'requestedBy',
                'reviewedBy',
            ])
            ->when($status === 'pending', fn ($query) => $query->awaitingAdmin())
            ->when($status !== 'pending' && $status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/ChangeRequests/Index', [
            'changeRequests' => $changeRequests,
            'filters' => ['status' => $status],
        ]);
    }

    /**
     * FR 1.9.4 - SISA signs off on a late change and it applies immediately.
     */
    public function approveChangeRequest(
        Request $request,
        BookingChangeRequest $changeRequest,
        BookingChangeService $changeService
    ): RedirectResponse {
        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $changeService->approve($changeRequest, $request->user(), $validated['admin_note'] ?? null);
        } catch (\App\Exceptions\BookingChangeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Change request approved and applied to the booking.');
    }

    /**
     * FR 1.9.4 - SISA rejects a late change; the booking keeps its slot.
     */
    public function rejectChangeRequest(
        Request $request,
        BookingChangeRequest $changeRequest,
        BookingChangeService $changeService
    ): RedirectResponse {
        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $changeService->reject($changeRequest, $request->user(), $validated['admin_note'] ?? null);
        } catch (\App\Exceptions\BookingChangeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Change request rejected. The booking keeps its original slot.');
    }
}
