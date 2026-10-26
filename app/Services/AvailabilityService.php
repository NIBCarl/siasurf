<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\TimePeriod;
use App\Exceptions\BookingRequestException;
use App\Models\Availability;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Collection;
/**
 * Single source of truth for locking and releasing an instructor's time slots.
 *
 * FR 1.8.3 - a slot is locked automatically once a reservation is confirmed and
 * must be handed back when that reservation goes away. Keeping the logic here
 * means every entry point (student checkout, instructor accept, payment expiry,
 * cancellation, reschedule) behaves identically.
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

        return Availability::updateOrCreate(
            [
                'user_id' => $instructorId,
                'specific_date' => $carbonDate->format('Y-m-d'),
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
     * Hand a slot back to the instructor.
     *
     * The lock is only removed when no other live booking still needs it, and
     * never for a block the instructor created by hand.
     *
     * @return bool Whether the lock was removed.
     */
    public function releaseSlot(
        int $instructorId,
        string $date,
        string $timePeriod,
        ?int $exceptBookingId = null
    ): bool {
        $carbonDate = Carbon::parse($date);

        // Someone else still holds this slot - leave it locked.
        if ($this->hasActiveBooking($instructorId, $carbonDate->format('Y-m-d'), $timePeriod, $exceptBookingId)) {
            return false;
        }

        // Only clear system-generated locks; manual blocks stay put.
        $deleted = Availability::query()
            ->where('user_id', $instructorId)
            ->whereDate('specific_date', $carbonDate->format('Y-m-d'))
            ->where('time_period', $timePeriod)
            ->where('is_available', false)
            ->whereNotNull('blocked_by_booking_id')
            ->delete();

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

        $this->blockForBooking($booking);
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
    // Bookability: the single place that answers "can this instructor be
    // booked for this slot?" It combines weekly availability, one-off blocks
    // and slots a live booking already owns.
    // ---------------------------------------------------------------------

    /**
     * The two periods for a date, each with a verdict and the reason.
     *
     * @return array<string, array{available: bool, reason: ?string, slots: array<int, string>}>
     */
    public function periodsFor(int $instructorId, Carbon|string $date): array
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

        // One-off blocks for this exact date: personal time and system locks.
        $blocks = Availability::query()
            ->where('user_id', $instructorId)
            ->whereDate('specific_date', $dateString)
            ->where('is_available', false)
            ->get(['time_period', 'blocked_by_booking_id']);

        $manualBlocks = $blocks
            ->filter(fn ($row) => $row->blocked_by_booking_id === null)
            ->map(fn ($row) => $row->time_period instanceof \BackedEnum ? $row->time_period->value : (string) $row->time_period)
            ->all();

        $bookingBlocks = $blocks
            ->filter(fn ($row) => $row->blocked_by_booking_id !== null)
            ->map(fn ($row) => $row->time_period instanceof \BackedEnum ? $row->time_period->value : (string) $row->time_period)
            ->all();

        $result = [];

        foreach (TimePeriod::cases() as $period) {
            $value = $period->value;
            $reason = null;

            if (!$hasPublishedSchedule) {
                // Nothing published: only explicit blocks apply.
                if (in_array($value, $manualBlocks, true)) {
                    $reason = 'The instructor has blocked this date as personal time.';
                } elseif (in_array($value, $bookingBlocks, true)) {
                    $reason = 'This slot is already taken by another lesson.';
                }
            } elseif (!in_array($value, $recurring, true)) {
                $reason = 'The instructor is not available during the '
                    . strtolower($period->label()) . ' on this day.';
            } elseif (in_array($value, $manualBlocks, true)) {
                $reason = 'The instructor has blocked this date as personal time.';
            } elseif (in_array($value, $bookingBlocks, true)) {
                $reason = 'This slot is already taken by another lesson.';
            }

            $result[$value] = [
                'available' => $reason === null,
                'reason' => $reason,
                'slots' => $reason === null
                    ? $this->bookableStartTimes($instructorId, $day, $value)
                    : [],
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
    public function bookableStartTimes(int $instructorId, Carbon|string $date, string $timePeriod): array
    {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        $period = TimePeriod::tryFrom($timePeriod);

        if (!$period) {
            return [];
        }

        $startHour = (int) explode(':', $period->startTime())[0];
        $endHour = (int) explode(':', $period->endTime())[0];

        // A live booking owns [start_time, end_time).
        $taken = Booking::query()
            ->where('instructor_id', $instructorId)
            ->whereDate('date', $day->format('Y-m-d'))
            ->whereIn('status', array_map(
                fn (BookingStatus $status) => $status->value,
                BookingStatus::slotHolding()
            ))
            ->get(['start_time', 'end_time'])
            ->map(fn ($booking) => [
                'start' => $booking->start_time ? substr((string) $booking->start_time, 0, 5) : null,
                'end' => $booking->end_time ? substr((string) $booking->end_time, 0, 5) : null,
            ])
            ->filter(fn ($row) => $row['start'] !== null && $row['end'] !== null)
            ->values()
            ->all();

        $slots = [];
        // A lesson needs at least this much lead time before it starts.
        $cutOff = now()->addHours(Booking::PRE_LESSON_BUFFER_HOURS);

        for ($hour = $startHour; $hour < $endHour; $hour++) {
            $slotStart = $day->copy()->setTime($hour, 0);
            $slotEnd = $slotStart->copy()->addHour();

            if ($slotStart->lessThanOrEqualTo($cutOff)) {
                continue;
            }

            $overlaps = false;

            foreach ($taken as $row) {
                $bookingStart = $day->copy()->setTime(
                    (int) explode(':', $row['start'])[0],
                    (int) explode(':', $row['start'])[1]
                );
                $bookingEnd = $day->copy()->setTime(
                    (int) explode(':', $row['end'])[0],
                    (int) explode(':', $row['end'])[1]
                );

                if ($slotStart->lessThan($bookingEnd) && $slotEnd->greaterThan($bookingStart)) {
                    $overlaps = true;
                    break;
                }
            }

            if (!$overlaps) {
                $slots[] = $slotStart->format('H:i');
            }
        }

        return $slots;
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
        string $startTime
    ): ?string {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);

        if ($day->isBefore(today())) {
            return 'That date is in the past.';
        }

        $periods = $this->periodsFor($instructorId, $day);
        $period = $periods[$timePeriod] ?? null;

        if (!$period) {
            return 'That time of day is not a valid booking period.';
        }

        if (!$period['available']) {
            return $period['reason'];
        }

        $normalised = strlen($startTime) === 5 ? $startTime : substr($startTime, 0, 5);

        if (!in_array($normalised, $period['slots'], true)) {
            return 'That start time is not available. Available times on this date: '
                . (empty($period['slots']) ? 'none left' : implode(', ', $period['slots'])) . '.';
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
        string $startTime
    ): void {
        $reason = $this->bookabilityReason($instructorId, $date, $timePeriod, $startTime);

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
            ->get(['day_of_week', 'time_period']);

        $summary = [];

        foreach (\App\Enums\DayOfWeek::cases() as $day) {
            $periods = $rows
                ->filter(function ($row) use ($day) {
                    $value = $row->day_of_week instanceof \BackedEnum
                        ? $row->day_of_week->value
                        : $row->day_of_week;

                    return (int) $value === $day->value;
                })
                ->map(fn ($row) => $row->time_period instanceof \BackedEnum
                    ? $row->time_period->value
                    : (string) $row->time_period)
                ->values()
                ->all();

            $summary[] = [
                'value' => $day->value,
                'label' => $day->label(),
                'short_label' => substr($day->label(), 0, 3),
                'periods' => $periods,
            ];
        }

        return $summary;
    }
}