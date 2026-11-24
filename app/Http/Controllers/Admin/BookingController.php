<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ChangeRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\BookingEvent;
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
        $query = Booking::with([
            'student',
            'instructor',
            'surfSpot',
            'payment',
            'latestRefund',
            'latestEvent',
            'events' => fn ($q) => $q->whereIn('event_type', BookingEvent::manifestTypes())->limit(6),
            'changeRequests' => fn ($q) => $q->reschedule()->latest('id')->limit(2),
        ])
            ->orderByRaw('COALESCE(last_event_at, created_at) DESC')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // Status filter
        if ($request->filled('status')) {
            if ($request->status === 'underpaid') {
                $query->where('payment_status', 'underpaid');
            } elseif ($request->status === 'rescheduled') {
                $query->where(function ($q) {
                    $q->whereNotNull('rescheduled_by_instructor_at')
                        ->orWhereHas('changeRequests', fn ($cr) => $cr->reschedule()->whereNotNull('applied_at'));
                });
            } else {
                $query->where('status', $request->status);
            }
        }

        $bookings = $query->paginate(20)->withQueryString();

        $bookings->through(function (Booking $booking) {
            $appliedReschedule = $booking->changeRequests
                ->first(fn (BookingChangeRequest $cr) => $cr->isReschedule() && $cr->applied_at !== null);

            return array_merge($booking->toArray(), [
                'is_rescheduled' => $booking->isRescheduled() || $appliedReschedule !== null,
                'reschedule_summary' => $appliedReschedule?->toProposalPayload(),
                'completed_event_types' => $booking->events
                    ->pluck('event_type')
                    ->unique()
                    ->values()
                    ->all(),
            ]);
        });

        $recentEvents = BookingEvent::with([
            'booking.student:id,name',
            'booking.instructor:id,name',
            'booking.surfSpot:id,name',
            'actor:id,name',
        ])
            ->whereIn('event_type', BookingEvent::manifestTypes())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (BookingEvent $event) => [
                'id' => $event->id,
                'booking_id' => $event->booking_id,
                'event_type' => $event->event_type,
                'title' => $event->title,
                'description' => $event->description,
                'metadata' => $event->metadata,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'actor_name' => $event->actor?->name,
                'student_name' => $event->booking?->student?->name,
                'instructor_name' => $event->booking?->instructor?->name,
                'spot_name' => $event->booking?->surfSpot?->name,
            ]);

        return Inertia::render('Admin/Bookings/Index', [
            'bookings' => $bookings,
            'recentEvents' => $recentEvents,
            'filters' => $request->only(['status']),
            'pendingChangeRequestCount' => BookingChangeRequest::query()->awaitingAdmin()->count(),
        ]);
    }

    /**
     * Display the specified booking details.
     */
    public function show(Booking $booking): Response
    {
        $booking->load([
            'student.studentProfile',
            'instructor',
            'surfSpot',
            'payment.verifiedBy',
            'latestRefund',
            'refunds.initiatedBy',
            'changeRequests.reviewedBy',
            'changeRequests.requestedBy',
            'events.actor',
            'waiver',
            'waivers',
            'participants.waivers',
            'review',
        ]);

        $booking->setAttribute('is_rescheduled', $booking->isRescheduled());

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
                'booking.payment',
                'booking.latestRefund',
                'requestedBy',
                'reviewedBy',
            ])
            ->when($status === 'pending', fn ($query) => $query->awaitingAdmin())
            ->when($status !== 'pending' && $status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END ASC', [
                ChangeRequestStatus::Pending->value,
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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
