<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\InstructorLevel;
use App\Enums\TimePeriod;
use App\Exceptions\BookingRequestException;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\InstructorProfile;
use Carbon\Carbon;
use Illuminate\Support\Collection;
/**
 * Single source of truth for locking and releasing an instructor's time slots.
 *
 * FR 1.8.3 - a slot is locked automatically once a reservation is confirmed and
 * must be handed back when that reservation goes away. Keeping the logic here
 * means every entry point (student checkout, instructor accept, payment expiry,
 * cancellation, reschedule) behaves identically.
 *
 * Slot capacity (this class is also the single source of truth for it):
 *  - Level 1 holds the whole slot as soon as a request is submitted.
 *  - Level 2 holds it once 1 student is booked (1-on-1).
 *  - Level 3 stays open until 5 students share the slot, summed across
 *    every live booking that overlaps the requested range.
 */
class AvailabilityService
{
    /**
     * Lock a slot because a booking now owns it.
     */
    public function blockSlot(
        int $instructorId,
        string $date,
        string $timePeriod,
        ?int $bookingId = null
    ): Availability {
        $carbonDate = Carbon::parse($date);
        $dateString = $carbonDate->format('Y-m-d');

        // Never convert a hand-made block (holiday, personal time) into a
        // booking lock. updateOrCreate would otherwise adopt that row, and the
        // later release would delete the instructor's own day off.
        $manualBlock = Availability::query()
            ->where('user_id', $instructorId)
            ->whereDate('specific_date', $dateString)
            ->where('time_period', $timePeriod)
            ->where('is_available', false)
            ->whereNull('blocked_by_booking_id')
            ->first();

        if ($manualBlock !== null) {
            return $manualBlock;
        }

        return Availability::updateOrCreate(
            [
                'user_id' => $instructorId,
                'specific_date' => $dateString,
                'time_period' => $timePeriod,
            ],
            [
                'day_of_week' => (int) $carbonDate->format('w'),
                'is_available' => false,
                'blocked_by_booking_id' => $bookingId,
            ]
        );
    }

    /**
     * Convenience wrapper for locking the slot a booking occupies.
     */
    public function blockForBooking(Booking $booking): Availability
    {
        return $this->blockSlot(
            $booking->instructor_id,
            $this->dateOf($booking),
            $this->timePeriodOf($booking),
            $booking->id
        );
    }

    /**
     * Lock the slot only when this booking leaves no capacity behind.
     *
     * Level 1 requests lock the slot the moment they are submitted (the whole
     * hour belongs to that one student group). Levels 2 and 3 keep the slot
     * open until their student capacity is consumed, so a 2-of-5 Level 3
     * booking must NOT close the calendar.
     */
    public function lockIfSlotIsFull(Booking $booking): ?Availability
    {
        $level = $this->instructorLevel($booking->instructor_id);

        if ($level->holdsSlotWhilePending()) {
            return $this->blockForBooking($booking);
        }

        $range = $this->bookingRange($booking);

        if ($range === null) {
            return $this->blockForBooking($booking);
        }

        $remaining = $this->remainingCapacity(
            $booking->instructor_id,
            $this->dateOf($booking),
            $range[0],
            $range[1],
        );

        return $remaining <= 0 ? $this->blockForBooking($booking) : null;
    }

    /**
     * Hand a slot back to the instructor.
     *
     * The system lock is removed, then rebuilt only if another live booking
     * still fills the slot's capacity. Without that second step, declining one
     * booking would leave a stale "unavailable" row behind, and declining one
     * of several Level 3 bookings would wrongly free the whole period.
     *
     * @return bool Whether any lock changed state.
     */
    public function releaseSlot(
        int $instructorId,
        string $date,
        string $timePeriod,
        ?int $exceptBookingId = null
    ): bool {
        $carbonDate = Carbon::parse($date);
        $dateString = $carbonDate->format('Y-m-d');

        // Only clear system-generated locks; manual blocks stay put.
        $deleted = Availability::query()
            ->where('user_id', $instructorId)
            ->whereDate('specific_date', $dateString)
            ->where('time_period', $timePeriod)
            ->where('is_available', false)
            ->whereNotNull('blocked_by_booking_id')
            ->delete();

        // Re-point the lock at any booking that still fills the slot.
        $remaining = Booking::query()
            ->where('instructor_id', $instructorId)
            ->whereDate('date', $dateString)
            ->where('time_period', $timePeriod)
            ->whereIn('status', array_map(
                fn (BookingStatus $status) => $status->value,
                BookingStatus::slotHolding()
            ))
            ->when($exceptBookingId, fn ($query) => $query->where('id', '!=', $exceptBookingId))
            ->get();

        foreach ($remaining as $booking) {
            if ($this->lockIfSlotIsFull($booking) !== null) {
                return true;
            }
        }

        return $deleted > 0;
    }

    /**
     * Convenience wrapper for releasing the slot a booking occupied.
     */
    public function releaseForBooking(Booking $booking): bool
    {
        return $this->releaseSlot(
            $booking->instructor_id,
            $this->dateOf($booking),
            $this->timePeriodOf($booking),
            $booking->id
        );
    }

    /**
     * Whether another live booking still occupies the slot.
     */
    public function hasActiveBooking(
        int $instructorId,
        string $date,
        string $timePeriod,
        ?int $exceptBookingId = null
    ): bool {
        return Booking::query()
            ->where('instructor_id', $instructorId)
            ->whereDate('date', $date)
            ->where('time_period', $timePeriod)
            ->whereIn('status', array_map(
                fn (BookingStatus $status) => $status->value,
                BookingStatus::slotHolding()
            ))
            ->when($exceptBookingId, fn ($query) => $query->where('id', '!=', $exceptBookingId))
            ->exists();
    }

    /**
     * Move a booking's lock from its old slot to a new one.
     */
    public function moveBookingSlot(Booking $booking, string $oldDate, string $oldTimePeriod): void
    {
        $this->releaseSlot(
            $booking->instructor_id,
            $oldDate,
            $oldTimePeriod,
            $booking->id
        );

        // Levels 2/3 keep the calendar open until their capacity is consumed.
        $this->lockIfSlotIsFull($booking);
    }

    /**
     * Manual blocks (holidays, personal time) the instructor can remove.
     */
    public function getManualBlocks(int $instructorId, bool $upcomingOnly = true): Collection
    {
        return Availability::query()
            ->where('user_id', $instructorId)
            ->manualBlock()
            ->whereNotNull('specific_date')
            ->when($upcomingOnly, fn ($query) => $query->whereDate('specific_date', '>=', today()))
            ->orderBy('specific_date')
            ->orderBy('time_period')
            ->get();
    }

    /**
     * Slots currently locked by live bookings.
     */
    public function getBookingBlocks(int $instructorId, bool $upcomingOnly = true): Collection
    {
        return Availability::query()
            ->with('blockedByBooking.student')
            ->where('user_id', $instructorId)
            ->bookingBlock()
            ->where('is_available', false)
            ->whereNotNull('specific_date')
            ->when($upcomingOnly, fn ($query) => $query->whereDate('specific_date', '>=', today()))
            ->orderBy('specific_date')
            ->orderBy('time_period')
            ->get();
    }

    /**
     * Normalised Y-m-d string for a booking date.
     */
    /**
     * Period-level availability for a directory view: which of the day's two
     * periods the instructor still has open.
     *
     * This applies the same rules as periodsFor() without enumerating start
     * times, so a page of twelve cards costs two queries instead of twelve
     * round trips. It answers "is their schedule open today", not "how many
     * start times remain".
     *
     * @param  array<int, int>  $instructorIds
     * @return array<int, array<int, string>> instructor id => open period values
     */
    public function openPeriodsForMany(array $instructorIds, Carbon|string $date): array
    {
        $instructorIds = array_values(array_unique($instructorIds));

        if ($instructorIds === []) {
            return [];
        }

        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        $dateString = $day->format('Y-m-d');
        $dayOfWeek = (int) $day->format('w');

        // Everything that touches this weekday or this exact date, in one read.
        $rows = Availability::query()
            ->whereIn('user_id', $instructorIds)
            ->where(function ($query) use ($dayOfWeek, $dateString) {
                $query->where(function ($recurring) use ($dayOfWeek) {
                    $recurring->whereNull('specific_date')->where('day_of_week', $dayOfWeek);
                })->orWhereDate('specific_date', $dateString);
            })
            ->get(['user_id', 'time_period', 'is_available', 'specific_date', 'blocked_by_booking_id']);

        // An instructor who has never published a weekly schedule is treated as
        // bookable, exactly like periodsFor() does.
        $published = Availability::query()
            ->whereIn('user_id', $instructorIds)
            ->whereNull('specific_date')
            ->distinct()
            ->pluck('user_id')
            ->flip();

        $periodValue = fn ($row) => $row->time_period instanceof \BackedEnum
            ? $row->time_period->value
            : (string) $row->time_period;

        $result = [];

        foreach ($instructorIds as $id) {
            $own = $rows->where('user_id', $id);

            $recurring = $own->whereNull('specific_date')
                ->where('is_available', true)
                ->map($periodValue)
                ->all();

            $dateRows = $own->whereNotNull('specific_date')->where('is_available', false);

            // Only hand-made blocks close a whole period. Bookings are exact
            // time ranges and are evaluated per start time, so they must not
            // make the entire morning look unavailable.
            $manualBlocks = $dateRows->whereNull('blocked_by_booking_id')->map($periodValue)->all();

            $open = [];

            foreach (TimePeriod::cases() as $period) {
                $value = $period->value;

                $isOpen = ! in_array($value, $manualBlocks, true)
                    && (! isset($published[$id]) || in_array($value, $recurring, true));

                if ($isOpen) {
                    $open[] = $value;
                }
            }

            $result[$id] = $open;
        }

        return $result;
    }

    public function dateOf(Booking $booking): string
    {
        return $booking->date instanceof \DateTimeInterface
            ? $booking->date->format('Y-m-d')
            : (string) $booking->date;
    }

    /**
     * Normalised time period string for a booking.
     */
    public function timePeriodOf(Booking $booking): string
    {
        $period = $booking->time_period;

        if ($period instanceof TimePeriod) {
            return $period->value;
        }

        if ($period instanceof \BackedEnum) {
            return (string) $period->value;
        }

        return (string) $period;
    }

    // ---------------------------------------------------------------------
    // Slot capacity: how many students may still share a time range
    // ---------------------------------------------------------------------

    /**
     * The instructor's certified level, defaulting to the most restrictive one
     * when the profile is missing so capacity can never be over-stated.
     */
    public function instructorLevel(int $instructorId): InstructorLevel
    {
        $level = InstructorProfile::query()
            ->where('user_id', $instructorId)
            ->value('level');

        if ($level instanceof InstructorLevel) {
            return $level;
        }

        return InstructorLevel::tryFrom((int) $level) ?? InstructorLevel::Level1;
    }

    /**
     * Maximum number of students that may share one time slot.
     *
     * The default comes from the certified level (Level 1 = 2, Level 2 = 1
     * because it is 1-on-1, Level 3 = 5). Operators can widen Level 2/3 in
     * `config/siasurf.php` when SISA changes the policy; SafetyService still
     * enforces the per-booking group ceiling.
     */
    public function slotCapacity(int $instructorId): int
    {
        $level = $this->instructorLevel($instructorId);

        $configured = (int) config(
            'siasurf.instructor_capacity.' . $level->value,
            $level->slotCapacity()
        );

        return max(1, $configured);
    }

    /**
     * Every live booking on a date (the statuses that still hold a slot).
     */
    private function liveBookingsOnDate(int $instructorId, Carbon $day, ?int $exceptBookingId = null): Collection
    {
        return Booking::query()
            ->where('instructor_id', $instructorId)
            ->whereDate('date', $day->format('Y-m-d'))
            ->whereIn('status', array_map(
                fn (BookingStatus $status) => $status->value,
                BookingStatus::slotHolding()
            ))
            ->when($exceptBookingId, fn ($query) => $query->where('id', '!=', $exceptBookingId))
            ->get();
    }

    /**
     * The [start, end] a booking actually occupies.
     *
     * Legacy rows may only have a time period, so the period start plus the
     * booked duration is used as the fallback. That keeps old data protected
     * instead of silently freeing a slot it still needs.
     *
     * @return array{0: string, 1: string}|null
     */
    private function bookingRange(Booking $booking): ?array
    {
        $start = $this->timeString($booking->start_time);

        if ($start === null) {
            $period = $booking->time_period instanceof TimePeriod
                ? $booking->time_period
                : TimePeriod::tryFrom((string) $booking->time_period);

            $start = $period?->startTime();
        }

        if ($start === null) {
            return null;
        }

        $end = $this->timeString($booking->end_time);

        if ($end === null) {
            $hours = max(1, (int) ($booking->duration_hours ?: Booking::standardHours()));
            $end = Carbon::createFromFormat('H:i', $start)->addHours($hours)->format('H:i');
        }

        if ($end <= $start) {
            return null;
        }

        return [$start, $end];
    }

    /**
     * How many students a booking counts as. Unknown headcounts count as one.
     */
    private function studentsIn(Booking $booking): int
    {
        return max(1, (int) ($booking->student_count ?: 1));
    }

    /**
     * Live bookings that overlap the given half-open range [start, end).
     */
    public function bookingsInRange(
        int $instructorId,
        Carbon|string $date,
        string $startTime,
        string $endTime,
        ?int $exceptBookingId = null
    ): Collection {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        $start = $this->timeString($startTime) ?? (string) $startTime;
        $end = $this->timeString($endTime) ?? (string) $endTime;

        return $this->liveBookingsOnDate($instructorId, $day, $exceptBookingId)
            ->filter(function (Booking $booking) use ($start, $end) {
                $range = $this->bookingRange($booking);

                return $range !== null && $range[0] < $end && $range[1] > $start;
            })
            ->values();
    }

    /**
     * Students already booked into a range.
     */
    public function bookedStudentsInRange(
        int $instructorId,
        Carbon|string $date,
        string $startTime,
        string $endTime,
        ?int $exceptBookingId = null
    ): int {
        return (int) $this->bookingsInRange($instructorId, $date, $startTime, $endTime, $exceptBookingId)
            ->sum(fn (Booking $booking) => $this->studentsIn($booking));
    }

    /**
     * Students that can still be added to a range.
     *
     * Level 1 returns zero as soon as any live booking overlaps: the slot is
     * exclusive while a request is pending.
     */
    public function remainingCapacity(
        int $instructorId,
        Carbon|string $date,
        string $startTime,
        string $endTime,
        ?int $exceptBookingId = null
    ): int {
        $level = $this->instructorLevel($instructorId);
        $capacity = $this->slotCapacity($instructorId);
        $overlapping = $this->bookingsInRange($instructorId, $date, $startTime, $endTime, $exceptBookingId);

        return $this->remainingCapacityWithin($overlapping, $level, $capacity, $startTime, $endTime);
    }

    /**
     * Same calculation, without hitting the database again.
     */
    private function remainingCapacityWithin(
        Collection $liveBookings,
        InstructorLevel $level,
        int $capacity,
        string $startTime,
        string $endTime
    ): int {
        $start = $this->timeString($startTime) ?? (string) $startTime;
        $end = $this->timeString($endTime) ?? (string) $endTime;

        $overlapping = $liveBookings->filter(function (Booking $booking) use ($start, $end) {
            $range = $this->bookingRange($booking);

            return $range !== null && $range[0] < $end && $range[1] > $start;
        });

        if ($overlapping->isEmpty()) {
            return $capacity;
        }

        if ($level->holdsSlotWhilePending()) {
            return 0;
        }

        $booked = (int) $overlapping->sum(fn (Booking $booking) => $this->studentsIn($booking));

        return max(0, $capacity - $booked);
    }

    // ---------------------------------------------------------------------
    // Bookability: the single place that answers "can this instructor be
    // booked for this slot?" It combines weekly availability, one-off blocks
    // and slots a live booking already owns.
    // ---------------------------------------------------------------------

    /**
     * The two periods for a date, each with a verdict and the reason.
     *
     * `$students` is the size of the party being booked. It is compared against
     * the instructor's remaining slot capacity, so a Level 3 slot with four of
     * five seats taken still lists its start time for a single student but not
     * for a group of two.
     *
     * @return array<string, array{available: bool, reason: ?string, slots: array<int, string>, duration_minutes: int, capacity: int, remaining_capacity: int}>
     */
    public function periodsFor(int $instructorId, Carbon|string $date, int $students = 1, ?int $exceptBookingId = null): array
    {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        $dateString = $day->format('Y-m-d');

        // Weekly pattern: a period is on only if the instructor turned it on.
        $recurring = Availability::query()
            ->where('user_id', $instructorId)
            ->whereNull('specific_date')
            ->where('is_available', true)
            ->where('day_of_week', (int) $day->format('w'))
            ->pluck('time_period')
            ->map(fn ($period) => $period instanceof \BackedEnum ? $period->value : (string) $period)
            ->all();

        // An instructor who has never published a weekly schedule must not be
        // silently unbookable - that turns a missing-data problem into a dead
        // end. Explicit date blocks are still honoured below.
        $hasPublishedSchedule = $this->hasPublishedSchedule($instructorId);

        // One-off blocks for this exact date: personal time the instructor
        // created by hand. System locks created by bookings are intentionally
        // NOT treated as a closed period any more: a Level 3 booking of 2
        // students must leave the rest of the morning open. Exact overlap and
        // remaining capacity are resolved per start time below.
        $blocks = Availability::query()
            ->where('user_id', $instructorId)
            ->whereDate('specific_date', $dateString)
            ->where('is_available', false)
            ->whereNull('blocked_by_booking_id')
            ->get(['time_period', 'blocked_by_booking_id']);

        $manualBlocks = $blocks
            ->map(fn ($row) => $row->time_period instanceof \BackedEnum ? $row->time_period->value : (string) $row->time_period)
            ->all();

        $level = $this->instructorLevel($instructorId);
        $result = [];

        foreach (TimePeriod::cases() as $period) {
            $value = $period->value;
            $reason = null;

            if (!$hasPublishedSchedule) {
                // Nothing published: only explicit blocks apply.
                if (in_array($value, $manualBlocks, true)) {
                    $reason = 'The instructor has blocked this date as personal time.';
                }
            } elseif (!in_array($value, $recurring, true)) {
                $reason = 'The instructor is not available during the '
                    . strtolower($period->label()) . ' on this day.';
            } elseif (in_array($value, $manualBlocks, true)) {
                $reason = 'The instructor has blocked this date as personal time.';
            }

            // Confirmed lessons are filtered by their exact start/end and by
            // the instructor's remaining student capacity inside
            // bookableStartTimes().
            $slots = $reason === null
                ? $this->bookableStartTimes($instructorId, $day, $value, $students, $exceptBookingId)
                : [];

            if ($reason === null && empty($slots)) {
                $reason = 'No bookable times are left in this period for that date.';
            }

            // "Remaining capacity" is measured at the next bookable start, not
            // across the whole period: a Level 3 instructor with 07:00 full can
            // still take students at 09:00.
            $remaining = empty($slots)
                ? 0
                : $this->remainingCapacity(
                    $instructorId,
                    $day,
                    $slots[0],
                    Carbon::createFromFormat('H:i', $slots[0])->addHour()->format('H:i'),
                    $exceptBookingId,
                );

            $result[$value] = [
                'available' => $reason === null,
                'reason' => $reason,
                'slots' => $reason === null ? $slots : [],
                // Lesson length the student will actually book by default
                // (drives the pre-selected end time and the price estimate).
                'duration_minutes' => $this->sessionMinutesFor($instructorId, $day, $value),
                'capacity' => $this->slotCapacity($instructorId),
                'remaining_capacity' => $remaining,
            ];
        }

        return $result;
    }

    /**
     * Hourly start times inside a period that no live booking already occupies,
     * and that are still far enough in the future to be booked.
     *
     * @return array<int, string>
     */
    public function bookableStartTimes(
        int $instructorId,
        Carbon|string $date,
        string $timePeriod,
        int $students = 1,
        ?int $exceptBookingId = null
    ): array {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        $period = TimePeriod::tryFrom($timePeriod);

        if (!$period) {
            return [];
        }

        $settings = $this->scheduleSettings($instructorId, $day, $period);

        // The start list is about "which hours still have room", not about one
        // fixed lesson length: the student chooses the end time separately, so
        // a start only has to fit the one-hour minimum here. bookableEndTimes()
        // then refines exactly how long the lesson may run.
        $minimumMinutes = $this->minimumLessonMinutes();
        $candidateSlots = $this->slotsInRange(
            $settings['start'],
            $settings['end'],
            $settings['step'],
            $minimumMinutes,
            $settings['open_slots']
        );

        $level = $this->instructorLevel($instructorId);
        $capacity = $this->slotCapacity($instructorId);
        $liveBookings = $this->liveBookingsOnDate($instructorId, $day, $exceptBookingId);
        $requested = max(1, $students);

        $slots = [];
        // A lesson needs at least this much lead time before it starts.
        $cutOff = now()->addHours(Booking::PRE_LESSON_BUFFER_HOURS);

        foreach ($candidateSlots as $time) {
            [$hour, $minute] = array_map('intval', explode(':', $time));
            $slotStart = $day->copy()->setTime($hour, $minute);

            if ($slotStart->lessThanOrEqualTo($cutOff)) {
                continue;
            }

            // Capacity is measured across the whole one-hour minimum window, so
            // a Level 3 slot with 4 of 5 students booked still accepts one more.
            $slotEnd = $slotStart->copy()->addMinutes($minimumMinutes)->format('H:i');

            if ($this->remainingCapacityWithin($liveBookings, $level, $capacity, $time, $slotEnd) < $requested) {
                continue;
            }

            $slots[] = $time;
        }

        return $slots;
    }

    /**
     * The end times a student may pick for a chosen start.
     *
     * Lesson length is expressed in whole hours (the pricing engine, the
     * session countdown and the change-request flow all assume integer hours).
     * An end time is offered only when the whole range still has enough
     * capacity for the party.
     *
     * @return array<int, string>
     */
    public function bookableEndTimes(
        int $instructorId,
        Carbon|string $date,
        string $startTime,
        int $students = 1,
        ?int $exceptBookingId = null
    ): array {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        $start = $this->timeString($startTime);

        if ($start === null) {
            return [];
        }

        $period = $this->periodForTime($start);
        $settings = $this->scheduleSettings($instructorId, $day, $period);

        if ($start < $settings['start'] || $start >= $settings['end']) {
            return [];
        }

        $minimumMinutes = $this->minimumLessonMinutes();

        if (!in_array($start, $this->slotsInRange(
            $settings['start'],
            $settings['end'],
            $settings['step'],
            $minimumMinutes,
            $settings['open_slots']
        ), true)) {
            return [];
        }

        $level = $this->instructorLevel($instructorId);
        $capacity = $this->slotCapacity($instructorId);
        $liveBookings = $this->liveBookingsOnDate($instructorId, $day, $exceptBookingId);
        $requested = max(1, $students);

        $ends = [];
        $cursor = Carbon::createFromFormat('H:i', $start)->addHour();
        $finish = Carbon::createFromFormat('H:i', $settings['end']);

        if ($cursor->greaterThan($finish)) {
            return [];
        }

        while ($cursor->lessThanOrEqualTo($finish)) {
            $end = $cursor->format('H:i');

            if ($this->remainingCapacityWithin($liveBookings, $level, $capacity, $start, $end) >= $requested) {
                $ends[] = $end;
            }

            $cursor->addHour();
        }

        return $ends;
    }

    /**
     * The shortest lesson the engine will quote.
     *
     * Booking length is validated in whole hours (pricing, the session countdown
     * and change requests all assume integer hours), so the picker always
     * offers one-hour steps even when an instructor configured a shorter slot.
     */
    private function minimumLessonMinutes(): int
    {
        return 60;
    }

    /**
     * Which half of the day a start time belongs to.
     */
    private function periodForTime(string $time): TimePeriod
    {
        return ((int) substr($time, 0, 2)) < 12 ? TimePeriod::Morning : TimePeriod::Afternoon;
    }

    /**
     * Resolve the instructor's custom working range and open-slot selection
     * for a day/period. Existing rows without a custom range fall back to the
     * period defaults, so older schedules keep working unchanged.
     *
     * `step` is how far apart start times are offered (always hourly, matching
     * the availability grid); `duration` is the lesson length, which must fit
     * inside the working range. When the instructor has not set a slot
     * duration, the platform standard (2h) applies.
     *
     * @return array{start: string, end: string, step: int, duration: int, open_slots: ?array<int, string>}
     */
    private function scheduleSettings(int $instructorId, Carbon $day, TimePeriod $period): array
    {
        $row = Availability::query()
            ->where('user_id', $instructorId)
            ->whereNull('specific_date')
            ->where('day_of_week', (int) $day->format('w'))
            ->where('time_period', $period->value)
            ->first();

        $start = $this->timeString($row?->start_time) ?? $period->startTime();
        $end = $this->timeString($row?->end_time) ?? $period->endTime();
        $duration = max(30, (int) ($row?->slot_duration_minutes ?? Booking::standardDurationMinutes()));
        $openSlots = $row?->open_slots;

        // Never trust an inverted range from old data.
        if ($start >= $end) {
            $start = $period->startTime();
            $end = $period->endTime();
        }

        return [
            'start' => $start,
            'end' => $end,
            // Starts are offered hourly; the lesson itself may span several hours.
            'step' => 60,
            'duration' => $duration,
            'open_slots' => is_array($openSlots) ? array_values($openSlots) : null,
        ];
    }

    /**
     * The lesson length (minutes) an instructor runs for a given day/period.
     * Falls back to the platform standard when they have no published schedule.
     */
    public function sessionMinutesFor(int $instructorId, Carbon|string $date, string $timePeriod): int
    {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        $period = TimePeriod::tryFrom($timePeriod);

        if (! $period) {
            return Booking::standardDurationMinutes();
        }

        return $this->scheduleSettings($instructorId, $day, $period)['duration'];
    }

    /**
     * Build candidate start times between a start and end.
     *
     * Starts advance by `$step` (hourly), and each candidate must leave room
     * for the whole lesson (`$duration`) before the range closes.
     *
     * @param array<int, string>|null $openSlots
     * @return array<int, string>
     */
    private function slotsInRange(
        string $start,
        string $end,
        int $step,
        int $duration,
        ?array $openSlots = null
    ): array {
        $cursor = Carbon::createFromFormat('H:i', $start);
        $finish = Carbon::createFromFormat('H:i', $end);

        if ($cursor->greaterThanOrEqualTo($finish)) {
            return [];
        }

        $allowed = $openSlots === null
            ? null
            : array_flip(array_map(fn ($slot) => substr((string) $slot, 0, 5), $openSlots));

        $slots = [];

        // The lesson must fit: cursor + duration <= finish.
        while ($cursor->copy()->addMinutes($duration)->lessThanOrEqualTo($finish)) {
            $value = $cursor->format('H:i');

            if ($allowed === null || isset($allowed[$value])) {
                $slots[] = $value;
            }

            $cursor->addMinutes($step);
        }

        return $slots;
    }

    private function timeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        $string = (string) $value;

        return str_contains($string, 'T')
            ? substr($string, 11, 5)
            : substr($string, 0, 5);
    }

    /**
     * Whether the instructor has ever published a weekly schedule. Used to tell
     * "has not set this up yet" apart from "deliberately unavailable".
     */
    public function hasPublishedSchedule(int $instructorId): bool
    {
        return Availability::query()
            ->where('user_id', $instructorId)
            ->whereNull('specific_date')
            ->exists();
    }

    /**
     * Whether the instructor can actually be booked for this date, period and
     * start time. Returns a human-readable reason when they cannot.
     */
    public function bookabilityReason(
        int $instructorId,
        Carbon|string $date,
        string $timePeriod,
        string $startTime,
        int $students = 1
    ): ?string {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);

        if ($day->isBefore(today())) {
            return 'That date is in the past.';
        }

        $periods = $this->periodsFor($instructorId, $day, $students);
        $period = $periods[$timePeriod] ?? null;

        if (!$period) {
            return 'That time of day is not a valid booking period.';
        }

        if (!$period['available']) {
            return $period['reason'];
        }

        $normalised = $this->timeString($startTime) ?? $startTime;

        if (!in_array($normalised, $period['slots'], true)) {
            return 'That start time is not available. Available times on this date: '
                . (empty($period['slots']) ? 'none left' : implode(', ', $period['slots'])) . '.';
        }

        return null;
    }

    /**
     * Validate a concrete [start, end] range, including the instructor's
     * remaining student capacity for that exact window.
     *
     * A null return means the range is bookable. The booking flow converts a
     * non-null answer into a BookingRequestException.
     */
    public function rangeBookabilityReason(
        int $instructorId,
        Carbon|string $date,
        string $startTime,
        string $endTime,
        int $students = 1,
        ?int $exceptBookingId = null
    ): ?string {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);

        if ($day->isBefore(today())) {
            return 'That date is in the past.';
        }

        $start = $this->timeString($startTime);
        $end = $this->timeString($endTime);

        if ($start === null || $end === null) {
            return 'Choose a valid start time and end time for the lesson.';
        }

        if ($end <= $start) {
            return 'The end time must be after the start time.';
        }

        $minutes = Carbon::createFromFormat('H:i', $start)
            ->diffInMinutes(Carbon::createFromFormat('H:i', $end));

        if ($minutes < 60) {
            return 'A lesson must run for at least one hour.';
        }

        if ($minutes % 60 !== 0) {
            return 'Lesson length must be in whole hours.';
        }

        if ($minutes > 8 * 60) {
            return 'A single lesson cannot run longer than 8 hours.';
        }

        $period = $this->periodForTime($start);

        if ($this->periodForTime($end) !== $period && $end !== $period->endTime()) {
            return 'A lesson cannot cross from morning into afternoon. Please shorten the session.';
        }

        $periods = $this->periodsFor($instructorId, $day, $students, $exceptBookingId);
        $verdict = $periods[$period->value] ?? null;

        if (!$verdict) {
            return 'That time of day is not a valid booking period.';
        }

        if (!$verdict['available']) {
            return $verdict['reason'];
        }

        $settings = $this->scheduleSettings($instructorId, $day, $period);

        if ($start < $settings['start'] || $end > $settings['end']) {
            return 'The instructor only teaches between '
                . $settings['start'] . ' and ' . $settings['end'] . ' on this day.';
        }

        $startOptions = $this->slotsInRange(
            $settings['start'],
            $settings['end'],
            $settings['step'],
            $this->minimumLessonMinutes(),
            $settings['open_slots']
        );

        if (!in_array($start, $startOptions, true)) {
            return 'That start time is not available on this date.';
        }

        $requested = max(1, $students);
        $remaining = $this->remainingCapacity($instructorId, $day, $start, $end, $exceptBookingId);

        if ($remaining < $requested) {
            return $remaining === 0
                ? 'That time slot is already fully booked. Please choose another time.'
                : 'Only ' . $remaining . ' ' . ($remaining === 1 ? 'spot is' : 'spots are')
                    . ' left in that time slot. Reduce the group size or choose another time.';
        }

        return null;
    }

    /**
     * Throwing wrapper so the booking flow can reject a request outright.
     *
     * @throws BookingRequestException
     */
    public function assertSlotIsBookable(
        int $instructorId,
        Carbon|string $date,
        string $timePeriod,
        string $startTime,
        int $students = 1
    ): void {
        $reason = $this->bookabilityReason($instructorId, $date, $timePeriod, $startTime, $students);

        if ($reason !== null) {
            throw new BookingRequestException($reason);
        }
    }

    /**
     * Throwing wrapper for a concrete start/end range. This is what the booking
     * form uses now that the student chooses both ends of the lesson.
     *
     * @throws BookingRequestException
     */
    public function assertRangeIsBookable(
        int $instructorId,
        Carbon|string $date,
        string $startTime,
        string $endTime,
        int $students = 1,
        ?int $exceptBookingId = null
    ): void {
        $reason = $this->rangeBookabilityReason(
            $instructorId,
            $date,
            $startTime,
            $endTime,
            $students,
            $exceptBookingId
        );

        if ($reason !== null) {
            throw new BookingRequestException($reason);
        }
    }

    /**
     * The instructor's weekly pattern, for display before booking.
     *
     * @return array<int, array{value: int, label: string, short_label: string, periods: array<int, string>}>
     */
    public function weeklyPattern(int $instructorId): array
    {
        $rows = Availability::query()
            ->where('user_id', $instructorId)
            ->whereNull('specific_date')
            ->where('is_available', true)
            ->get(['day_of_week', 'time_period', 'start_time', 'end_time', 'open_slots']);

        $summary = [];

        foreach (\App\Enums\DayOfWeek::cases() as $day) {
            $dayRows = $rows->filter(function ($row) use ($day) {
                $value = $row->day_of_week instanceof \BackedEnum
                    ? $row->day_of_week->value
                    : $row->day_of_week;

                return (int) $value === $day->value;
            });

            $periods = $dayRows
                ->map(fn ($row) => $row->time_period instanceof \BackedEnum
                    ? $row->time_period->value
                    : (string) $row->time_period)
                ->values()
                ->all();

            $ranges = $dayRows->map(function ($row) {
                $period = $row->time_period instanceof \BackedEnum
                    ? TimePeriod::tryFrom($row->time_period->value)
                    : TimePeriod::tryFrom((string) $row->time_period);

                if ($period === null) {
                    return null;
                }

                $start = $this->timeString($row->start_time) ?? $period->startTime();
                $end = $this->timeString($row->end_time) ?? $period->endTime();

                return [
                    'period' => $period->value,
                    'period_label' => $period->label(),
                    'start' => $start,
                    'end' => $end,
                    'label' => $start . '–' . $end,
                ];
            })->filter()->values()->all();

            $summary[] = [
                'value' => $day->value,
                'label' => $day->label(),
                'short_label' => substr($day->label(), 0, 3),
                'periods' => $periods,
                'ranges' => $ranges,
            ];
        }

        return $summary;
    }
}