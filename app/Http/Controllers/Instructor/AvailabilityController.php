<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Availability;
use App\Models\Booking;
use App\Enums\DayOfWeek;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
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
    public function index(Request $request, AvailabilityService $availabilityService): Response
    {
        $user = auth()->user();

        $availabilityService->releaseExpiredCheckoutHolds($user->id);

        $rawFocusDate = $request->query('date');
        $focusDate = null;
        if (is_string($rawFocusDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFocusDate)) {
            try {
                $focusDate = \Carbon\Carbon::createFromFormat('Y-m-d', $rawFocusDate, config('app.timezone', 'Asia/Manila'))->format('Y-m-d');
            } catch (\Throwable) {
                $focusDate = null;
            }
        }

        $availabilities = Availability::where('user_id', $user->id)
            ->orderBy('day_of_week')
            ->orderBy('time_period')
            ->get()
            ->groupBy('day_of_week');

        return Inertia::render('Instructor/Availability/Index', [
            'availabilities' => $availabilities,
            'initialSchedule' => $availabilityService->instructorWeekSchedule($user->id, $focusDate),
            'initialFocusDate' => $focusDate,
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
     * Dated weekly schedule JSON for the Instructor Booking Calendar.
     */
    public function schedule(Request $request, AvailabilityService $availabilityService): JsonResponse
    {
        $validated = $request->validate([
            'week' => 'nullable|date',
        ]);

        return response()->json(
            $availabilityService->instructorWeekSchedule(auth()->id(), $validated['week'] ?? null)
        );
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

            $dayOfWeek = (int) \Carbon\Carbon::parse($date, config('app.timezone', 'Asia/Manila'))->format('w');

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
            ->where('is_available', false)
            ->whereNull('blocked_by_booking_id');

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
            'students' => 'nullable|integer|min:1|max:10',
            'skill_level' => 'nullable|in:beginner,intermediate,advanced',
            'session_type' => 'nullable|in:teaching,guiding,coaching',
            'except_booking_id' => 'nullable|integer|exists:bookings,id',
        ]);

        $date = \Carbon\Carbon::parse($validated['date']);
        $students = (int) ($validated['students'] ?? 1);
        [$skill, $sessionType] = $this->resolveSkillAndService($request, $validated);
        $exceptBookingId = $this->resolveExceptBookingId($validated['except_booking_id'] ?? null, (int) $instructorId);

        $availability = app(AvailabilityService::class);
        $periods = $availability->periodsFor((int) $instructorId, $date, $students, $exceptBookingId, $skill, $sessionType);

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
            'slot_capacity' => $availability->slotCapacity((int) $instructorId, $skill),
        ]);
    }

    /**
     * Day-by-day availability for a whole month window.
     *
     * The booking form's calendar needs this to disable dates the instructor
     * has blocked. Calling the single-date endpoint once per day would cost
     * ~42 requests per month view; this resolves the range in three queries.
     */
    public function getCalendar(Request $request, $instructorId): JsonResponse
    {
        $validated = $request->validate([
            'start' => 'nullable|date',
            'days' => 'nullable|integer|min:1|max:120',
            'students' => 'nullable|integer|min:1|max:10',
            'skill_level' => 'nullable|in:beginner,intermediate,advanced',
            'session_type' => 'nullable|in:teaching,guiding,coaching',
            'except_booking_id' => 'nullable|integer|exists:bookings,id',
        ]);

        $students = (int) ($validated['students'] ?? 1);
        [$skill, $sessionType] = $this->resolveSkillAndService($request, $validated);
        $exceptBookingId = $this->resolveExceptBookingId($validated['except_booking_id'] ?? null, (int) $instructorId);

        $availability = app(AvailabilityService::class);

        // The booking horizon is owned by the service so the calendar, the form
        // and the server-side assertion cannot drift apart.
        $horizon = $availability->bookingHorizonDays();
        $days = min((int) ($validated['days'] ?? $horizon), $horizon);

        $start = isset($validated['start'])
            ? \Carbon\Carbon::parse($validated['start'])
            : now();

        // Never hand back days that are already past.
        if ($start->isBefore(now()->startOfDay())) {
            $days = max(0, $days - (int) now()->startOfDay()->diffInDays($start));
            $start = now()->startOfDay();
        }

        $days = max(1, $days);

        return response()->json([
            'start' => $start->format('Y-m-d'),
            'days' => $days,
            'students' => $students,
            'horizon_days' => $horizon,
            'slot_capacity' => $availability->slotCapacity((int) $instructorId, $skill),
            'days_map' => $availability->availabilityByDay(
                (int) $instructorId,
                $start,
                $days,
                $students,
                $exceptBookingId,
                $skill,
                $sessionType
            ),
        ]);
    }

    private function resolveExceptBookingId(mixed $rawId, int $instructorId): ?int
    {
        if (! $rawId) {
            return null;
        }

        $booking = Booking::query()
            ->whereKey((int) $rawId)
            ->where('instructor_id', $instructorId)
            ->first(['id']);

        return $booking?->id;
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
            'skill_level' => 'nullable|in:beginner,intermediate,advanced',
            'session_type' => 'nullable|in:teaching,guiding,coaching',
        ]);

        $students = (int) ($validated['students'] ?? 1);
        [$skill, $sessionType] = $this->resolveSkillAndService($request, $validated);
        $availability = app(AvailabilityService::class);
        $minimumMinutes = $availability->minimumLessonMinutes($sessionType, $skill);

        $endTimes = $availability->bookableEndTimes(
            (int) $instructorId,
            $validated['date'],
            $validated['start_time'],
            $students,
            null,
            $skill,
            $sessionType,
        );

        $candidateEnd = \Carbon\Carbon::createFromFormat('H:i', $validated['start_time'])
            ->addMinutes($minimumMinutes)
            ->format('H:i');
        $probeEnd = $candidateEnd > $validated['start_time']
            ? $candidateEnd
            : $validated['start_time'];

        $unavailable = empty($endTimes)
            ? $availability->unavailableStartReason(
                (int) $instructorId,
                $validated['date'],
                $validated['start_time'],
                $students,
                null,
                $skill,
                $sessionType,
            )
            : ['is_reserved' => false, 'reason' => null];

        $endTimeSpots = [];
        foreach ($endTimes as $end) {
            $spot = $availability->lockedSurfSpotForRange(
                (int) $instructorId,
                $validated['date'],
                $validated['start_time'],
                $end
            );
            $endTimeSpots[$end] = $spot?->toMapPayload();
        }

        return response()->json([
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'students' => $students,
            'minimum_minutes' => $minimumMinutes,
            'end_times' => $endTimes,
            'end_time_spots' => $endTimeSpots,
            'locked_surf_spot' => ! empty($endTimes) ? ($endTimeSpots[$endTimes[0]] ?? null) : null,
            'is_reserved' => $unavailable['is_reserved'],
            'reason' => $unavailable['reason'],
            'remaining_capacity' => $availability->remainingCapacity(
                (int) $instructorId,
                $validated['date'],
                $validated['start_time'],
                $endTimes[0] ?? $probeEnd,
                null,
                $skill,
                $sessionType,
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: ?SkillLevel, 1: ?SessionType}
     */
    private function resolveSkillAndService(Request $request, array $validated): array
    {
        $rawSkill = $validated['skill_level'] ?? null;

        if ($rawSkill === null && $request->user()?->isStudent()) {
            $profileSkill = $request->user()->studentProfile?->skill_level;
            $rawSkill = $profileSkill instanceof SkillLevel ? $profileSkill->value : $profileSkill;
        }

        $skill = $rawSkill !== null ? SkillLevel::tryFrom((string) $rawSkill) : null;
        $sessionType = $skill !== null
            ? SessionType::forSkill($skill)
            : (isset($validated['session_type'])
                ? SessionType::tryFrom((string) $validated['session_type'])
                : null);

        return [$skill, $sessionType];
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
