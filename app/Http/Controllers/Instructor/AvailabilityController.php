<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Availability;
use App\Models\Booking;
use App\Enums\DayOfWeek;
use App\Enums\TimePeriod;
use App\Services\AvailabilityService;
use App\Services\SafetyService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AvailabilityController extends Controller
{
    /**
     * Display instructor's availability
     */
    public function index(AvailabilityService $availabilityService): Response
    {
        $user = auth()->user();

        $availabilities = Availability::where('user_id', $user->id)
            ->orderBy('day_of_week')
            ->orderBy('time_period')
            ->get()
            ->groupBy('day_of_week');

        return Inertia::render('Instructor/Availability/Index', [
            'availabilities' => $availabilities,
            'daysOfWeek' => collect(DayOfWeek::cases())->map(fn($day) => [
                'name' => $day->name,
                'value' => $day->value,
                'label' => $day->label(),
            ]),
            'timePeriods' => collect(TimePeriod::cases())->map(fn($period) => [
                'name' => $period->name,
                'value' => $period->value,
                'label' => $period->label(),
            ]),
            'profile' => $user->instructorProfile,
            // FR 1.9.3 - Instant Book: auto-confirm requests on this calendar.
            'autoAcceptBookings' => (bool) ($user->instructorProfile?->auto_accept_bookings),
            // Default lesson length new availability rows are saved with.
            'standardSessionMinutes' => Booking::standardDurationMinutes(),
            // FR 1.8.1 - personal time the instructor can still remove.
            'manualBlocks' => $availabilityService->getManualBlocks($user->id)
                ->map(fn(Availability $block) => [
                    'id' => $block->id,
                    'date' => $block->specific_date?->format('Y-m-d'),
                    'date_label' => $block->specific_date?->format('M d, Y'),
                    'time_period' => $block->time_period?->value,
                    'time_period_label' => $block->time_period?->label(),
                ])
                ->values(),
            // FR 1.8.3 - slots the system locked because a booking owns them.
            'bookingBlocks' => $availabilityService->getBookingBlocks($user->id)
                ->map(fn(Availability $block) => [
                    'id' => $block->id,
                    'date' => $block->specific_date?->format('Y-m-d'),
                    'date_label' => $block->specific_date?->format('M d, Y'),
                    'time_period_label' => $block->time_period?->label(),
                    'booking_ref' => $block->blocked_by_booking_id
                        ? '#SURF-' . str_pad((string) $block->blocked_by_booking_id, 5, '0', STR_PAD_LEFT)
                        : null,
                    'student_name' => $block->blockedByBooking?->student?->name,
                ])
                ->values(),
        ]);
    }

    /**
     * Update weekly recurring availability
     */
    public function updateWeekly(Request $request): RedirectResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'availabilities' => 'required|array',
            'availabilities.*.day_of_week' => 'required|integer|between:0,6',
            'availabilities.*.time_period' => 'required|in:morning,afternoon',
            'availabilities.*.is_available' => 'required|boolean',
            'availabilities.*.start_time' => 'nullable|date_format:H:i',
            'availabilities.*.end_time' => 'nullable|date_format:H:i',
            'availabilities.*.open_slots' => 'nullable|array',
            'availabilities.*.open_slots.*' => 'date_format:H:i',
            // 60 = 1-hour lesson, 120 = the standard 2-hour lesson.
            'availabilities.*.slot_duration_minutes' => 'nullable|integer|in:60,120',
        ]);

        foreach ($validated['availabilities'] as $index => $availability) {
            $period = TimePeriod::from($availability['time_period']);
            $start = $availability['start_time'] ?? $period->startTime();
            $end = $availability['end_time'] ?? $period->endTime();
            $duration = (int) ($availability['slot_duration_minutes'] ?? Booking::standardDurationMinutes());
            $openSlots = $availability['open_slots'] ?? null;

            if ($start >= $end) {
                throw ValidationException::withMessages([
                    "availabilities.{$index}.end_time" => 'The end time must be after the start time.',
                ]);
            }

            if ($start < $period->startTime() || $end > $period->endTime()) {
                throw ValidationException::withMessages([
                    "availabilities.{$index}.start_time" => 'The selected hours must sit inside the '
                        . strtolower($period->label()) . ' window.',
                ]);
            }

            if ($availability['is_available'] && is_array($openSlots) && count($openSlots) === 0) {
                throw ValidationException::withMessages([
                    "availabilities.{$index}.open_slots" => 'Choose at least one open slot, or switch this period off.',
                ]);
            }

            if (is_array($openSlots)) {
                foreach ($openSlots as $slot) {
                    if ($slot < $start || $slot >= $end) {
                        throw ValidationException::withMessages([
                            "availabilities.{$index}.open_slots" => 'Every open slot must be inside the working range.',
                        ]);
                    }
                }
            }

            Availability::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'day_of_week' => $availability['day_of_week'],
                    'time_period' => $availability['time_period'],
                    'specific_date' => null,
                ],
                [
                    'start_time' => $start,
                    'end_time' => $end,
                    'open_slots' => is_array($openSlots)
                        ? array_values(array_unique(array_map(fn ($slot) => substr((string) $slot, 0, 5), $openSlots)))
                        : null,
                    'slot_duration_minutes' => $duration,
                    'is_available' => $availability['is_available'],
                ]
            );
        }

        return back()->with('success', 'Weekly availability updated successfully.');
    }

    /**
     * Block specific dates
     */
    public function blockDates(Request $request): RedirectResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'dates' => 'required|array',
            'dates.*' => 'required|date|after_or_equal:today',
            'time_period' => 'nullable|in:morning,afternoon',
        ]);

        foreach ($validated['dates'] as $date) {
            // Blocking a whole day covers both periods.
            $periods = $validated['time_period']
                ? [$validated['time_period']]
                : ['morning', 'afternoon'];

            $dayOfWeek = (int) \Carbon\Carbon::parse($date)->format('w');

            foreach ($periods as $period) {
                // firstOrCreate keeps any existing booking ownership intact, so
                // blocking a date that a confirmed lesson already holds does not
                // turn a system lock into a manual one.
                $block = Availability::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'specific_date' => $date,
                        'time_period' => $period,
                    ],
                    [
                        'day_of_week' => $dayOfWeek,
                        'is_available' => false,
                    ]
                );

                if (!$block->is_available) {
                    continue;
                }

                $block->update(['is_available' => false]);
            }
        }

        return back()->with('success', 'Dates blocked successfully.');
    }

    /**
     * Unblock specific dates
     */
    public function unblockDates(Request $request): RedirectResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'date' => 'required|date',
            'time_period' => 'nullable|in:morning,afternoon',
        ]);

        $query = Availability::where('user_id', $user->id)
            ->where('specific_date', $validated['date'])
            ->where('is_available', false);

        if ($validated['time_period']) {
            $query->where('time_period', $validated['time_period']);
        }

        $query->delete();

        return back()->with('success', 'Date unblocked successfully.');
    }

    /**
     * Get available slots for a date (public API).
     *
     * Returns a per-period verdict plus the concrete start times a student can
     * actually pick, and a reason when a period is closed. `available_slots` is
     * kept for backwards compatibility.
     */
    public function getAvailableSlots(Request $request, $instructorId): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            // Party size drives the capacity check: a Level 3 slot with 4 of 5
            // seats taken is bookable for one student but not for two.
            'students' => 'nullable|integer|min:1|max:10',
        ]);

        $date = \Carbon\Carbon::parse($validated['date']);
        $students = (int) ($validated['students'] ?? 1);

        $availability = app(AvailabilityService::class);
        $periods = $availability->periodsFor((int) $instructorId, $date, $students);

        $availableSlots = [];
        foreach ($periods as $value => $period) {
            $availableSlots[$value] = $period['available'];
        }

        return response()->json([
            'date' => $date->format('Y-m-d'),
            'day_label' => $date->format('l'),
            'available_slots' => $availableSlots,
            'periods' => $periods,
            'has_any_slot' => collect($periods)->contains(fn ($period) => $period['available']),
            'students' => $students,
            'slot_capacity' => $availability->slotCapacity((int) $instructorId),
        ]);
    }

    /**
     * End times the student may choose for a given start time.
     *
     * The booking form shows a start selector and an end selector; this is the
     * server's answer to "which end times still have room for this party?".
     */
    public function getAvailableEndTimes(Request $request, $instructorId): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'students' => 'nullable|integer|min:1|max:10',
        ]);

        $students = (int) ($validated['students'] ?? 1);
        $availability = app(AvailabilityService::class);

        $endTimes = $availability->bookableEndTimes(
            (int) $instructorId,
            $validated['date'],
            $validated['start_time'],
            $students,
        );

        return response()->json([
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'students' => $students,
            'end_times' => $endTimes,
            'remaining_capacity' => $availability->remainingCapacity(
                (int) $instructorId,
                $validated['date'],
                $validated['start_time'],
                $endTimes[0] ?? $validated['start_time'],
            ),
        ]);
    }

    /**
     * Get booked time slots for an instructor on a given date (API endpoint).
     */
    public function getBookedSlots(Request $request, $instructorId): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
        ]);

        $safetyService = app(SafetyService::class);
        $bookedSlots = $safetyService->getBookedSlots((int) $instructorId, $validated['date']);

        return response()->json([
            'date' => $validated['date'],
            'booked_slots' => $bookedSlots,
        ]);
    }

    /**
     * Toggle instructor status (Vacation Mode)
     */
    public function toggleStatus(): RedirectResponse
    {
        $user = auth()->user();
        $profile = $user->instructorProfile;

        if ($profile->status === \App\Enums\InstructorStatus::Active) {
            $profile->update(['status' => \App\Enums\InstructorStatus::Inactive]);
            $message = 'Vacation Mode enabled. You are now hidden from search.';
        } elseif ($profile->status === \App\Enums\InstructorStatus::Inactive) {
            $profile->update(['status' => \App\Enums\InstructorStatus::Active]);
            $message = 'Vacation Mode disabled. You are now visible to tourists!';
        } else {
            return back()->with('error', 'You cannot toggle status while suspended or pending verification.');
        }

        return back()->with('success', $message);
    }

    /**
     * Instant Book: when enabled, booking requests on this calendar are
     * auto-confirmed instead of waiting in the 2-hour acceptance queue.
     */
    public function toggleInstantBook(): RedirectResponse
    {
        $profile = auth()->user()->instructorProfile;

        if (!$profile) {
            return back()->with('error', 'Complete your instructor onboarding first.');
        }

        $enabled = !$profile->auto_accept_bookings;

        $profile->update(['auto_accept_bookings' => $enabled]);

        return back()->with(
            'success',
            $enabled
                ? 'Instant Book enabled. New requests will be confirmed automatically.'
                : 'Instant Book disabled. You will review requests before they are confirmed.'
        );
    }
}
