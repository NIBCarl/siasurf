<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\InstructorLevel;
use App\Enums\PaymentStatus;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
use App\Enums\TimePeriod;
use App\Exceptions\BookingRequestException;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\InstructorProfile;
use App\Models\SurfSpot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
/**
 * Single source of truth for locking and releasing an instructor's time slots.
 *
 * FR 1.8.3 - a slot is locked automatically once a reservation is confirmed and
 * must be handed back when that reservation goes away. Keeping the logic here
 * means every entry point (student checkout, instructor accept, payment expiry,
 * cancellation, reschedule) behaves identically.
 *
 * Slot capacity (this class is also the single source of truth for it):
 *  - Level 1 is 1-on-1 (max 1 student) and holds the whole slot as soon as a request is submitted.
 *  - Level 2 holds the slot once 2 students are booked.
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

        $bookingSkill = $booking->skill_level instanceof SkillLevel
            ? $booking->skill_level
            : SkillLevel::tryFrom((string) $booking->skill_level);
        $bookingService = $booking->session_type instanceof SessionType
            ? $booking->session_type
            : SessionType::tryFrom((string) $booking->session_type);

        $remaining = $this->remainingCapacity(
            $booking->instructor_id,
            $this->dateOf($booking),
            $range[0],
            $range[1],
            null,
            $bookingSkill,
            $bookingService,
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
     * The default comes from the certified level (Level 1 = 1 because it is
     * 1-on-1, Level 2 = 2, Level 3 = 5). Operators can widen Level 2/3 in
     * `config/siasurf.php` when SISA changes the policy; SafetyService still
     * enforces the per-booking group ceiling.
     */
    public function slotCapacity(int $instructorId, ?SkillLevel $skill = null): int
    {
        $level = $this->instructorLevel($instructorId);

        if ($skill !== null) {
            return $level->maxStudentsFor($skill);
        }

        $configured = (int) config(
            'siasurf.instructor_capacity.' . $level->value,
            $level->slotCapacity()
        );

        return max(1, $configured);
    }

    /**
     * Automatically expire and release any 15-minute student waiver/checkout
     * holds that have passed their deadline without completing payment selection.
     */
    public function releaseExpiredCheckoutHolds(int $instructorId): int
    {
        $count = 0;

        $expiredHolds = Booking::query()
            ->where('instructor_id', $instructorId)
            ->inStudentCheckout()
            ->where('payment_deadline_at', '<', now())
            ->get();

        foreach ($expiredHolds as $booking) {
            $booking->expireAcceptance('Waiver and checkout hold expired after ' . Booking::CHECKOUT_HOLD_MINUTES . ' minutes.');
            $this->releaseForBooking($booking);
            $count++;
        }

        $count += $this->releaseExpiredAwaitingPayments(
            Booking::query()->where('instructor_id', $instructorId)
        );

        return $count;
    }

    /**
     * Automatically expire and release any 15-minute checkout holds belonging
     * to a specific student that have passed their deadline.
     */
    public function releaseExpiredCheckoutHoldsForStudent(int $studentId): int
    {
        $count = 0;

        $expiredHolds = Booking::query()
            ->where('student_id', $studentId)
            ->inStudentCheckout()
            ->where('payment_deadline_at', '<', now())
            ->get();

        foreach ($expiredHolds as $booking) {
            $booking->expireAcceptance('Waiver and checkout hold expired after ' . Booking::CHECKOUT_HOLD_MINUTES . ' minutes.');
            $this->releaseForBooking($booking);
            $count++;
        }

        $count += $this->releaseExpiredAwaitingPayments(
            Booking::query()->where('student_id', $studentId)
        );

        return $count;
    }

    /**
     * Lazily expire AwaitingPayment holds whose 15-minute payment deadline
     * passed without a completed payment or receipt awaiting verification.
     */
    private function releaseExpiredAwaitingPayments($scopedQuery): int
    {
        $ids = (clone $scopedQuery)
            ->where('status', BookingStatus::AwaitingPayment)
            ->whereNotNull('payment_deadline_at')
            ->where('payment_deadline_at', '<', now())
            ->where('payment_status', '!=', PaymentStatus::Completed->value)
            ->whereDoesntHave('payment', function ($q) {
                $q->whereIn('status', [
                    PaymentStatus::Completed->value,
                    PaymentStatus::PendingVerification->value,
                ]);
            })
            ->pluck('id');

        $count = 0;

        foreach ($ids as $id) {
            $released = DB::transaction(function () use ($id): bool {
                $locked = Booking::with('payment')
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked || $locked->status !== BookingStatus::AwaitingPayment) {
                    return false;
                }

                if ($locked->payment?->isCompleted() || $locked->payment?->isAwaitingVerification()) {
                    return false;
                }

                if ($locked->payment_deadline_at && $locked->payment_deadline_at->isFuture()) {
                    return false;
                }

                $locked->markPaymentTimedOut();

                if ($locked->payment && $locked->payment->status === PaymentStatus::Pending) {
                    $locked->payment->update(['status' => PaymentStatus::Failed]);
                }

                $this->releaseForBooking($locked);

                return true;
            });

            if ($released) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Release any unfinished waiver/checkout holds for a specific student and
     * instructor (e.g. when the student clicks Back to change their details).
     */
    public function releaseStudentCheckoutHolds(int $studentId, int $instructorId, ?int $exceptBookingId = null): int
    {
        $holds = Booking::query()
            ->where('student_id', $studentId)
            ->where('instructor_id', $instructorId)
            ->inStudentCheckout()
            ->when($exceptBookingId, fn ($q) => $q->where('id', '!=', $exceptBookingId))
            ->get();

        $count = 0;

        foreach ($holds as $booking) {
            $booking->cancel(
                'Booking details updated or abandoned before completing waiver/checkout.',
                \App\Enums\CancellationReason::Student
            );
            $this->releaseForBooking($booking);
            $count++;
        }

        return $count;
    }

    /**
     * Every live booking on a date (the statuses that still hold a slot).
     */
    private function liveBookingsOnDate(int $instructorId, Carbon $day, ?int $exceptBookingId = null): Collection
    {
        $this->releaseExpiredCheckoutHolds($instructorId);

        return Booking::query()
            ->with('surfSpot')
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
        ?int $exceptBookingId = null,
        ?SkillLevel $skill = null,
        ?SessionType $sessionType = null,
        ?int $surfSpotId = null
    ): int {
        $level = $this->instructorLevel($instructorId);
        $capacity = $this->slotCapacity($instructorId, $skill);
        $overlapping = $this->bookingsInRange($instructorId, $date, $startTime, $endTime, $exceptBookingId);

        return $this->remainingCapacityWithin($overlapping, $level, $capacity, $startTime, $endTime, $skill, $sessionType, $surfSpotId);
    }

    /**
     * Same calculation, without hitting the database again.
     *
     * Evaluated hour-by-hour across `[start, end)` so a longer span cannot
     * hide a second-hour skill/service/spot conflict or double-count
     * back-to-back bookings across consecutive hours.
     */
    private function remainingCapacityWithin(
        Collection $liveBookings,
        InstructorLevel $level,
        int $capacity,
        string $startTime,
        string $endTime,
        ?SkillLevel $skill = null,
        ?SessionType $sessionType = null,
        ?int $surfSpotId = null
    ): int {
        $start = $this->timeString($startTime) ?? (string) $startTime;
        $end = $this->timeString($endTime) ?? (string) $endTime;

        $overlapping = $liveBookings->filter(function (Booking $booking) use ($start, $end) {
            $range = $this->bookingRange($booking);

            return $range !== null && $range[0] < $end && $range[1] > $start;
        });

        if ($overlapping->isEmpty()) {
            return $skill !== null ? $level->maxStudentsFor($skill) : $capacity;
        }

        // An instructor cannot teach at two beaches simultaneously or bridge
        // across consecutive bookings at different surf spots.
        $distinctSpotIds = $overlapping
            ->pluck('surf_spot_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($distinctSpotIds->count() > 1) {
            return 0;
        }

        if ($surfSpotId !== null && $distinctSpotIds->count() === 1 && $distinctSpotIds->first() !== $surfSpotId) {
            return 0;
        }

        $cursor = Carbon::createFromFormat('H:i', substr($start, 0, 5));
        $finish = Carbon::createFromFormat('H:i', substr($end, 0, 5));

        if (! $cursor || ! $finish || $cursor->greaterThanOrEqualTo($finish)) {
            return 0;
        }

        $minRemaining = $skill !== null ? $level->maxStudentsFor($skill) : $capacity;

        while ($cursor->lessThan($finish)) {
            $next = $cursor->copy()->addHour();
            if ($next->greaterThan($finish)) {
                $next = $finish->copy();
            }

            $hStart = $cursor->format('H:i');
            $hEnd = $next->format('H:i');

            $hourBookings = $overlapping->filter(function (Booking $booking) use ($hStart, $hEnd) {
                $range = $this->bookingRange($booking);

                return $range !== null && $range[0] < $hEnd && $range[1] > $hStart;
            });

            if ($hourBookings->isEmpty()) {
                $hourRemaining = $skill !== null ? $level->maxStudentsFor($skill) : $capacity;
            } elseif ($level->holdsSlotWhilePending()) {
                return 0;
            } else {
                $firstExistingSkill = null;

                foreach ($hourBookings as $existingBooking) {
                    if (! $existingBooking instanceof Booking) {
                        continue;
                    }

                    $existingSkill = $this->skillOf($existingBooking);
                    $existingService = $this->sessionTypeOf($existingBooking);

                    if ($firstExistingSkill === null && $existingSkill !== null) {
                        $firstExistingSkill = $existingSkill;
                    }

                    if ($skill !== null && $existingSkill !== null && $existingSkill !== $skill) {
                        return 0;
                    }

                    if ($sessionType !== null && $existingService !== null && $existingService !== $sessionType) {
                        return 0;
                    }
                }

                $effectiveCapacity = $firstExistingSkill !== null
                    ? $level->maxStudentsFor($firstExistingSkill)
                    : $capacity;

                $booked = (int) $hourBookings->sum(fn (Booking $booking) => $this->studentsIn($booking));
                $hourRemaining = max(0, $effectiveCapacity - $booked);
            }

            $minRemaining = min($minRemaining, $hourRemaining);

            if ($minRemaining <= 0) {
                return 0;
            }

            $cursor = $next;
        }

        return max(0, $minRemaining);
    }

    private function skillOf(Booking $booking): ?SkillLevel
    {
        if ($booking->skill_level instanceof SkillLevel) {
            return $booking->skill_level;
        }

        return $booking->skill_level !== null
            ? SkillLevel::tryFrom((string) $booking->skill_level)
            : null;
    }

    private function sessionTypeOf(Booking $booking): ?SessionType
    {
        if ($booking->session_type instanceof SessionType) {
            return $booking->session_type;
        }

        return $booking->session_type !== null
            ? SessionType::tryFrom((string) $booking->session_type)
            : null;
    }

    private function slotMismatchReason(
        int $instructorId,
        Carbon $day,
        string $start,
        string $end,
        ?int $exceptBookingId,
        ?SkillLevel $skill,
        ?SessionType $sessionType,
        ?int $surfSpotId = null
    ): ?string {
        $overlapping = $this->bookingsInRange($instructorId, $day, $start, $end, $exceptBookingId);

        if ($overlapping->isEmpty()) {
            return null;
        }

        if ($skill !== null) {
            foreach ($overlapping as $existing) {
                $existingSkill = $this->skillOf($existing);
                if ($existingSkill !== null && $existingSkill !== $skill) {
                    return sprintf(
                        'This time slot is already reserved for a %s session. Shared slots require all students to be the same skill level.',
                        $existingSkill->label()
                    );
                }
            }
        }

        if ($sessionType !== null) {
            foreach ($overlapping as $existing) {
                $existingService = $this->sessionTypeOf($existing);
                if ($existingService !== null && $existingService !== $sessionType) {
                    return sprintf(
                        'This time slot is already reserved for a %s session. Please pick another time.',
                        $existingService->label()
                    );
                }
            }
        }

        $distinctSpotIds = $overlapping
            ->pluck('surf_spot_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($distinctSpotIds->count() > 1) {
            return 'This time range overlaps existing sessions at different surf spots. Choose a shorter duration or another start time.';
        }

        if ($surfSpotId !== null && $distinctSpotIds->count() === 1 && $distinctSpotIds->first() !== $surfSpotId) {
            $spotName = $overlapping->first()?->surfSpot?->name ?? 'the scheduled beach';

            return sprintf(
                'This shared time slot is already scheduled at %s. Joining this slot requires matching the existing surf spot.',
                $spotName
            );
        }

        return null;
    }

    /**
     * When all bookings overlapping `[startTime, endTime)` share a single surf spot,
     * return that SurfSpot so new students joining the shared slot are locked to it.
     */
    public function lockedSurfSpotForRange(
        int $instructorId,
        Carbon|string $date,
        string $startTime,
        string $endTime,
        ?int $exceptBookingId = null
    ): ?SurfSpot {
        $overlapping = $this->bookingsInRange($instructorId, $date, $startTime, $endTime, $exceptBookingId);

        if ($overlapping->isEmpty()) {
            return null;
        }

        $distinctSpotIds = $overlapping
            ->pluck('surf_spot_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($distinctSpotIds->count() !== 1) {
            return null;
        }

        return $overlapping->first(fn (Booking $b) => $b->surf_spot_id === $distinctSpotIds->first())?->surfSpot;
    }

    /**
     * Return all slot-holding bookings connected to `$booking` through overlapping
     * hours on the same instructor & date (including `$booking` itself).
     *
     * @return Collection<int, Booking>
     */
    public function overlappingSessionBookings(Booking $booking): Collection
    {
        $this->releaseExpiredCheckoutHolds($booking->instructor_id);

        $day = $this->dateOf($booking);
        $targetRange = $this->bookingRange($booking);

        $bookings = Booking::query()
            ->with(['student', 'surfSpot', 'instructor'])
            ->where('instructor_id', $booking->instructor_id)
            ->whereDate('date', $day)
            ->whereIn('status', array_map(
                fn (BookingStatus $status) => $status->value,
                BookingStatus::slotHolding()
            ))
            ->orderBy('id')
            ->get();

        if ($targetRange === null) {
            return $bookings->where('id', $booking->id)->values();
        }

        $clusterIds = [$booking->id => true];
        $clusterRanges = [$targetRange];

        do {
            $added = false;

            foreach ($bookings as $candidate) {
                if (isset($clusterIds[$candidate->id])) {
                    continue;
                }

                $candidateRange = $this->bookingRange($candidate);
                if ($candidateRange === null) {
                    continue;
                }

                foreach ($clusterRanges as $existingRange) {
                    if ($candidateRange[0] < $existingRange[1] && $candidateRange[1] > $existingRange[0]) {
                        $clusterIds[$candidate->id] = true;
                        $clusterRanges[] = $candidateRange;
                        $added = true;
                        break;
                    }
                }
            }
        } while ($added);

        return $bookings
            ->filter(fn (Booking $candidate) => isset($clusterIds[$candidate->id]))
            ->values();
    }

    /**
     * Day-by-day availability for a date range, in a bounded number of queries.
     *
     * `periodsFor()` is one date per call. A month grid would need ~42 calls
     * and the same number of round trips, so this resolves the whole window
     * from three reads instead: the weekly pattern, the manual blocks in the
     * range, and the live bookings in the range.
     *
     * Deliberately coarse: a day reports whether a period has ANY bookable
     * start, not the start times themselves. The student still calls
     * periodsFor() for the day they actually pick.
     *
     * @return array<string, array<string, array{available: bool, reason: ?string, remaining_capacity: int}>>
     */
    public function availabilityByDay(
        int $instructorId,
        Carbon|string $start,
        int $days = 60,
        int $students = 1,
        ?int $exceptBookingId = null,
        ?SkillLevel $skill = null,
        ?SessionType $sessionType = null
    ): array {
        $this->releaseExpiredCheckoutHolds($instructorId);

        $from = ($start instanceof Carbon ? $start->copy() : Carbon::parse($start))->startOfDay();
        $days = max(1, min($days, 120));
        $to = $from->copy()->addDays($days - 1)->endOfDay();

        // 1. Weekly pattern, one read for every weekday in the range.
        $weekly = Availability::query()
            ->where('user_id', $instructorId)
            ->whereNull('specific_date')
            ->where('is_available', true)
            ->get(['day_of_week', 'time_period']);

        $periodValue = fn ($period) => $period instanceof \BackedEnum
            ? $period->value
            : (string) $period;

        $recurring = [];

        foreach ($weekly as $row) {
            $day = $row->day_of_week instanceof \BackedEnum
                ? (int) $row->day_of_week->value
                : (int) $row->day_of_week;

            $recurring[$day][$periodValue($row->time_period)] = true;
        }

        $hasPublishedSchedule = $weekly->isNotEmpty();

        // 2. Manual blocks across the whole range, keyed by date.
        $manualBlocks = Availability::query()
            ->where('user_id', $instructorId)
            ->whereBetween('specific_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->where('is_available', false)
            ->whereNull('blocked_by_booking_id')
            ->get(['specific_date', 'time_period'])
            ->groupBy(fn ($row) => $row->specific_date instanceof \DateTimeInterface
                ? $row->specific_date->format('Y-m-d')
                : (string) $row->specific_date)
            ->map(fn ($rows) => $rows->map(fn ($row) => $periodValue($row->time_period))->all())
            ->all();

        // 3. Live bookings holding slots in the range, keyed by date.
        $liveBookings = Booking::query()
            ->with(['instructor', 'surfSpot'])
            ->where('instructor_id', $instructorId)
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->whereIn('status', array_map(
                fn (BookingStatus $status) => $status->value,
                BookingStatus::slotHolding()
            ))
            ->when($exceptBookingId, fn ($query) => $query->where('id', '!=', $exceptBookingId))
            ->get()
            ->groupBy(fn (Booking $booking) => $this->dateOf($booking));

        $level = $this->instructorLevel($instructorId);
        $capacity = $this->slotCapacity($instructorId, $skill);
        $minimumMinutes = $this->minimumLessonMinutes($sessionType, $skill);
        $result = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $day = $from->copy()->addDays($offset);
            $dateString = $day->format('Y-m-d');
            $dayOfWeek = (int) $day->format('w');

            $dayBlocks = $manualBlocks[$dateString] ?? [];
            $dayBookings = $liveBookings[$dateString] ?? collect();

            $periods = [];

            foreach (TimePeriod::cases() as $period) {
                $value = $period->value;
                $reason = null;

                if ($hasPublishedSchedule && ! isset($recurring[$dayOfWeek][$value])) {
                    $reason = 'Not available during the '
                        . strtolower($period->label()) . ' on this day.';
                } elseif (in_array($value, $dayBlocks, true)) {
                    $reason = 'Blocked as personal time.';
                }

                $remaining = 0;
                $afterCutOffStarts = [];

                if ($reason === null) {
                    // The single-date path (periodsFor) drops individual starts
                    // inside the lead-time buffer, then reports the period as
                    // available if ANY start survives. Checking only the period
                    // start here was wrong: at 08:00 the 06:00 period start is
                    // inside the buffer, so the calendar closed the whole
                    // morning even though 10:00 and 11:00 were bookable.
                    $cutOff = now()->addHours(Booking::PRE_LESSON_BUFFER_HOURS);
                    $settings = $this->scheduleSettings($instructorId, $day, $period);

                    $survivingStarts = $this->slotsInRange(
                        $settings['start'],
                        $settings['end'],
                        $settings['step'],
                        $minimumMinutes,
                        $settings['open_slots']
                    );

                    foreach ($survivingStarts as $candidate) {
                        [$hour, $minute] = array_map('intval', explode(':', $candidate));
                        $candidateStart = $day->copy()->setTime($hour, $minute);

                        if ($candidateStart->greaterThan($cutOff)) {
                            $afterCutOffStarts[] = $candidate;
                        }
                    }

                    if ($afterCutOffStarts === []) {
                        $reason = 'Too soon to book — lessons need at least '
                            . Booking::PRE_LESSON_BUFFER_HOURS . ' hour of lead time.';
                    }
                }

                if ($reason === null) {
                    $bestRemaining = 0;

                    foreach ($afterCutOffStarts as $candidate) {
                        $slotEnd = Carbon::createFromFormat('H:i', $candidate)
                            ->addMinutes($minimumMinutes)
                            ->format('H:i');

                        $slotRemaining = $this->remainingCapacityWithin(
                            $dayBookings,
                            $level,
                            $capacity,
                            $candidate,
                            $slotEnd,
                            $skill,
                            $sessionType
                        );

                        if ($slotRemaining > $bestRemaining) {
                            $bestRemaining = $slotRemaining;
                        }
                    }

                    $remaining = $bestRemaining;

                    if ($remaining < max(1, $students)) {
                        $reason = $remaining === 0
                            ? 'That time is already reserved.'
                            : 'Only ' . $remaining . ' spot' . ($remaining === 1 ? '' : 's') . ' left.';
                        $remaining = max(0, $remaining);
                    }
                }

                $periods[$value] = [
                    'available' => $reason === null,
                    'reason' => $reason,
                    'remaining_capacity' => $remaining,
                ];
            }

            $result[$dateString] = $periods;
        }

        return $result;
    }

    // ---------------------------------------------------------------------
    // Bookability: the single place that answers "can this instructor be
    // booked for this slot?" It combines weekly availability, one-off blocks
    // and slots a live booking already owns.
    // ---------------------------------------------------------------------

    /**
     * Explain why a period with a published schedule has no bookable start.
     *
     * The generic "no bookable times" message hides the most common real
     * cause: the instructor is mid-lesson. A student who sees only "fully
     * booked" assumes the day is gone, when the truth is "busy until 10:00,
     * free after".
     *
     * The end time is derived from the live session's own start + duration —
     * NOT a blanket two hours — so a lesson that began at 07:00 frees the
     * instructor at 09:00 rather than blocking until noon.
     */
    private function unbookableReason(int $instructorId, Carbon $day, string $timePeriod): ?string
    {
        // Deliberately NOT liveBookingsOnDate(): that helper calls
        // releaseExpiredCheckoutHolds(), which mutates. Calling it from this
        // read path released the lock that had just been created for a
        // confirmed booking. Query the live session directly instead.
        $live = Booking::query()
            ->with('instructor')
            ->where('instructor_id', $instructorId)
            ->whereDate('date', $day->format('Y-m-d'))
            ->where('status', BookingStatus::InProgress->value)
            ->first();

        if ($live === null) {
            return null;
        }

        $freeAt = $this->sessionFreeAt($live);

        if ($freeAt === null) {
            return sprintf(
                '%s is currently teaching a lesson, so this period is unavailable.',
                $live->instructor?->name ?? 'The instructor'
            );
        }

        return sprintf(
            '%s is currently teaching until %s. The earliest a new lesson can start is %s.',
            $live->instructor?->name ?? 'The instructor',
            $freeAt->format('g:i A'),
            $freeAt->format('g:i A')
        );
    }

    /**
     * When a live session is expected to finish, from its own start + duration.
     */
    private function sessionFreeAt(Booking $booking): ?Carbon
    {
        if ($booking->started_at === null) {
            return null;
        }

        $minutes = $this->sessionMinutesFor(
            $booking->instructor_id,
            $this->dateOf($booking),
            $booking->time_period instanceof \BackedEnum
                ? $booking->time_period->value
                : (string) $booking->time_period
        );

        return $booking->started_at->copy()->addMinutes($minutes);
    }

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
    public function periodsFor(
        int $instructorId,
        Carbon|string $date,
        int $students = 1,
        ?int $exceptBookingId = null,
        ?SkillLevel $skill = null,
        ?SessionType $sessionType = null
    ): array {
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
        $minimumMinutes = $this->minimumLessonMinutes($sessionType, $skill);
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
                ? $this->bookableStartTimes($instructorId, $day, $value, $students, $exceptBookingId, $skill, $sessionType)
                : [];

            $reservedSlots = $reason === null
                ? $this->reservedStartTimes($instructorId, $day, $value, $students, $exceptBookingId, $skill, $sessionType)
                : [];

            $reservedSlotDetails = $reason === null && ! empty($reservedSlots)
                ? $this->reservedSlotDetails($instructorId, $day, $reservedSlots, $exceptBookingId, $skill, $sessionType, $minimumMinutes)
                : [];

            if ($reason === null && empty($slots)) {
                $reason = $this->unbookableReason($instructorId, $day, $value)
                    ?? (! empty($reservedSlots)
                        ? 'All ' . $value . ' times are already reserved by other students. Please choose another time or date.'
                        : 'No bookable times are left in this period for that date.');
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
                    Carbon::createFromFormat('H:i', $slots[0])->addMinutes($minimumMinutes)->format('H:i'),
                    $exceptBookingId,
                    $skill,
                    $sessionType,
                );

            $result[$value] = [
                'available' => $reason === null,
                'reason' => $reason,
                'slots' => $reason === null ? $slots : [],
                'reserved_slots' => $reservedSlots,
                'reserved_slot_details' => $reservedSlotDetails,
                'slot_remaining' => $reason === null ? collect($slots)->mapWithKeys(fn (string $slotTime) => [
                    $slotTime => $this->remainingCapacity(
                        $instructorId,
                        $day,
                        $slotTime,
                        Carbon::createFromFormat('H:i', $slotTime)->addMinutes($minimumMinutes)->format('H:i'),
                        $exceptBookingId,
                        $skill,
                        $sessionType,
                    ),
                ])->all() : [],
                // Lesson length the student will actually book by default
                // (drives the pre-selected end time and the price estimate).
                'duration_minutes' => $this->sessionMinutesFor($instructorId, $day, $value),
                'minimum_minutes' => $minimumMinutes,
                'capacity' => $this->slotCapacity($instructorId, $skill),
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
        ?int $exceptBookingId = null,
        ?SkillLevel $skill = null,
        ?SessionType $sessionType = null
    ): array {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        $period = TimePeriod::tryFrom($timePeriod);

        if (!$period) {
            return [];
        }

        $settings = $this->scheduleSettings($instructorId, $day, $period);

        $minimumMinutes = $this->minimumLessonMinutes($sessionType, $skill);
        $candidateSlots = $this->slotsInRange(
            $settings['start'],
            $settings['end'],
            $settings['step'],
            $minimumMinutes,
            $settings['open_slots']
        );

        $level = $this->instructorLevel($instructorId);
        $capacity = $this->slotCapacity($instructorId, $skill);
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

            // Capacity is measured across the full minimum lesson window.
            $slotEnd = $slotStart->copy()->addMinutes($minimumMinutes)->format('H:i');

            if ($this->remainingCapacityWithin($liveBookings, $level, $capacity, $time, $slotEnd, $skill, $sessionType) < $requested) {
                continue;
            }

            $slots[] = $time;
        }

        return $slots;
    }

    /**
     * Hourly start times inside a period that already have a live booking
     * leaving insufficient room for the requested party, skill, or service.
     *
     * @return array<int, string>
     */
    public function reservedStartTimes(
        int $instructorId,
        Carbon|string $date,
        string $timePeriod,
        int $students = 1,
        ?int $exceptBookingId = null,
        ?SkillLevel $skill = null,
        ?SessionType $sessionType = null
    ): array {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        $period = TimePeriod::tryFrom($timePeriod);

        if (!$period) {
            return [];
        }

        $liveBookings = $this->liveBookingsOnDate($instructorId, $day, $exceptBookingId);

        if ($liveBookings->isEmpty()) {
            return [];
        }

        $settings = $this->scheduleSettings($instructorId, $day, $period);
        $minimumMinutes = $this->minimumLessonMinutes($sessionType, $skill);
        $candidateSlots = $this->slotsInRange(
            $settings['start'],
            $settings['end'],
            $settings['step'],
            $minimumMinutes,
            $settings['open_slots']
        );

        $level = $this->instructorLevel($instructorId);
        $capacity = $this->slotCapacity($instructorId, $skill);
        $requested = max(1, $students);
        $cutOff = now()->addHours(Booking::PRE_LESSON_BUFFER_HOURS);

        $reserved = [];

        foreach ($candidateSlots as $time) {
            [$hour, $minute] = array_map('intval', explode(':', $time));
            $slotStart = $day->copy()->setTime($hour, $minute);

            if ($slotStart->lessThanOrEqualTo($cutOff)) {
                continue;
            }

            $slotEnd = $slotStart->copy()->addMinutes($minimumMinutes)->format('H:i');

            $hasOverlap = $liveBookings->contains(function (Booking $booking) use ($time, $slotEnd) {
                $range = $this->bookingRange($booking);

                return $range !== null && $range[0] < $slotEnd && $range[1] > $time;
            });

            if (! $hasOverlap) {
                continue;
            }

            if ($this->remainingCapacityWithin($liveBookings, $level, $capacity, $time, $slotEnd, $skill, $sessionType) < $requested) {
                $reserved[] = $time;
            }
        }

        return $reserved;
    }

    /**
     * Structured explanations for reserved start times so the UI can distinguish
     * a full slot from a cross-skill / cross-service lock or seat limit.
     *
     * @param  array<int, string>  $reservedSlots
     * @return array<string, array{reason_code: string, existing_skill: ?string, existing_service: ?string, label: string, short_label: string}>
     */
    private function reservedSlotDetails(
        int $instructorId,
        Carbon $day,
        array $reservedSlots,
        ?int $exceptBookingId,
        ?SkillLevel $skill,
        ?SessionType $sessionType,
        int $minimumMinutes
    ): array {
        $liveBookings = $this->liveBookingsOnDate($instructorId, $day, $exceptBookingId);
        $level = $this->instructorLevel($instructorId);
        $capacity = $this->slotCapacity($instructorId, $skill);
        $details = [];

        foreach ($reservedSlots as $time) {
            $slotEnd = Carbon::createFromFormat('H:i', $time)->addMinutes($minimumMinutes)->format('H:i');
            $clockLabel = Carbon::createFromFormat('H:i', $time)->format('g:i A');

            $overlapping = $liveBookings->filter(function (Booking $booking) use ($time, $slotEnd) {
                $range = $this->bookingRange($booking);

                return $range !== null && $range[0] < $slotEnd && $range[1] > $time;
            })->values();

            $mismatchSkill = null;
            $mismatchService = null;

            foreach ($overlapping as $existing) {
                $existingSkill = $this->skillOf($existing);
                $existingService = $this->sessionTypeOf($existing);

                if ($skill !== null && $existingSkill !== null && $existingSkill !== $skill) {
                    $mismatchSkill = $existingSkill;
                    $mismatchService = $existingService;
                    break;
                }

                if ($sessionType !== null && $existingService !== null && $existingService !== $sessionType) {
                    $mismatchService = $existingService;
                }
            }

            $first = $overlapping->first();
            $firstSkill = $first instanceof Booking ? $this->skillOf($first) : null;
            $firstService = $first instanceof Booking ? $this->sessionTypeOf($first) : null;

            if ($mismatchSkill !== null) {
                $details[$time] = [
                    'reason_code' => 'skill_mismatch',
                    'existing_skill' => $mismatchSkill->value,
                    'existing_service' => $mismatchService?->value,
                    'label' => "{$clockLabel} — Reserved for a {$mismatchSkill->label()} session",
                    'short_label' => "Reserved ({$mismatchSkill->label()})",
                ];
                continue;
            }

            if ($mismatchService !== null) {
                $details[$time] = [
                    'reason_code' => 'service_mismatch',
                    'existing_skill' => $firstSkill?->value,
                    'existing_service' => $mismatchService->value,
                    'label' => "{$clockLabel} — Reserved for {$mismatchService->label()}",
                    'short_label' => "Reserved ({$mismatchService->label()})",
                ];
                continue;
            }

            $distinctSpots = $overlapping
                ->pluck('surf_spot_id')
                ->filter(fn ($id) => $id !== null)
                ->map(fn ($id) => (int) $id)
                ->unique();

            if ($distinctSpots->count() > 1) {
                $details[$time] = [
                    'reason_code' => 'spot_conflict',
                    'existing_skill' => $firstSkill?->value,
                    'existing_service' => $firstService?->value,
                    'label' => "{$clockLabel} — Already reserved",
                    'short_label' => 'Already reserved',
                ];
                continue;
            }

            $remainingForOne = $this->remainingCapacityWithin(
                $overlapping,
                $level,
                $capacity,
                $time,
                $slotEnd,
                $skill,
                $sessionType
            );

            if ($remainingForOne > 0) {
                $seatWord = $remainingForOne === 1 ? 'seat' : 'seats';
                $details[$time] = [
                    'reason_code' => 'insufficient_seats',
                    'existing_skill' => $firstSkill?->value,
                    'existing_service' => $firstService?->value,
                    'label' => "{$clockLabel} — Only {$remainingForOne} {$seatWord} left",
                    'short_label' => "{$remainingForOne} {$seatWord} left",
                ];
                continue;
            }

            $details[$time] = [
                'reason_code' => 'full',
                'existing_skill' => $firstSkill?->value,
                'existing_service' => $firstService?->value,
                'label' => "{$clockLabel} — Already reserved",
                'short_label' => 'Already reserved',
            ];
        }

        return $details;
    }

    /**
     * The end times a student may pick for a chosen start.
     *
     * Lesson length is expressed in whole hours (the pricing engine, the
     * session countdown and the change-request flow all assume integer hours).
     * Stops extending as soon as an hour is closed on the schedule or lacks
     * enough seats/compatibility for the party.
     *
     * @return array<int, string>
     */
    public function bookableEndTimes(
        int $instructorId,
        Carbon|string $date,
        string $startTime,
        int $students = 1,
        ?int $exceptBookingId = null,
        ?SkillLevel $skill = null,
        ?SessionType $sessionType = null
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

        $minimumMinutes = $this->minimumLessonMinutes($sessionType, $skill);

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
        $capacity = $this->slotCapacity($instructorId, $skill);
        $liveBookings = $this->liveBookingsOnDate($instructorId, $day, $exceptBookingId);
        $requested = max(1, $students);

        $ends = [];
        $cursor = Carbon::createFromFormat('H:i', $start)->addMinutes($minimumMinutes);
        $finish = Carbon::createFromFormat('H:i', $settings['end']);

        if ($cursor->greaterThan($finish)) {
            return [];
        }

        while ($cursor->lessThanOrEqualTo($finish)) {
            $end = $cursor->format('H:i');

            if (! $this->rangeFitsOpenSlots($start, $end, $settings)) {
                break;
            }

            if ($this->remainingCapacityWithin($liveBookings, $level, $capacity, $start, $end, $skill, $sessionType) < $requested) {
                break;
            }

            $ends[] = $end;
            $cursor->addHour();
        }

        return $ends;
    }

    /**
     * Automated reschedule start-time picker for an existing booking.
     *
     * Excludes the booking itself, preserves its exact duration_hours, skill
     * level, and session type, and derives end_time + time_period automatically.
     *
     * @return array<string, mixed>
     */
    public function rescheduleStartTimes(Booking $booking, Carbon|string $date): array
    {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        $dateString = $day->format('Y-m-d');
        $durationHours = $booking->resolvedDurationHours();
        $students = max(1, (int) ($booking->student_count ?: 1));
        $skill = $this->skillOf($booking);
        $sessionType = $this->sessionTypeOf($booking);

        $currentDate = $this->dateOf($booking);
        $currentStart = $this->timeString($booking->start_time);

        $rawPeriods = $this->periodsFor(
            $booking->instructor_id,
            $day,
            $students,
            $booking->id,
            $skill,
            $sessionType
        );

        $periods = [];
        $hasAnySlot = false;

        foreach (TimePeriod::cases() as $period) {
            $key = $period->value;
            $periodData = $rawPeriods[$key] ?? ['available' => false, 'reason' => 'Unavailable', 'slots' => []];
            $slots = [];

            if ($periodData['available'] && ! empty($periodData['slots'])) {
                foreach ($periodData['slots'] as $startTime) {
                    $endTime = Carbon::createFromFormat('H:i', $startTime)
                        ->addHours($durationHours)
                        ->format('H:i');

                    $reason = $this->rangeBookabilityReason(
                        $booking->instructor_id,
                        $day,
                        $startTime,
                        $endTime,
                        $students,
                        $booking->id,
                        $skill,
                        $sessionType,
                        $booking->surf_spot_id ? (int) $booking->surf_spot_id : null
                    );

                    if ($reason !== null) {
                        continue;
                    }

                    $isCurrent = $dateString === $currentDate && $startTime === $currentStart;
                    $slots[] = [
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'time_period' => $key,
                        'start_label' => Carbon::createFromFormat('H:i', $startTime)->format('g:i A'),
                        'end_label' => Carbon::createFromFormat('H:i', $endTime)->format('g:i A'),
                        'is_current' => $isCurrent,
                    ];

                    if (! $isCurrent) {
                        $hasAnySlot = true;
                    }
                }
            }

            $reason = $periodData['reason'];
            if ($reason === null && empty($slots)) {
                $reason = "No {$durationHours}-hour start times are open during the " . strtolower($period->label()) . ' on this date.';
            }

            $periods[$key] = [
                'available' => ! empty($slots),
                'reason' => empty($slots) ? $reason : null,
                'slots' => $slots,
            ];
        }

        return [
            'date' => $dateString,
            'day_label' => $day->format('l, M d, Y'),
            'duration_hours' => $durationHours,
            'periods' => $periods,
            'has_any_slot' => $hasAnySlot,
        ];
    }

    /**
     * Explain why a chosen start time has no bookable end times.
     *
     * @return array{is_reserved: bool, reason: string}
     */
    public function unavailableStartReason(
        int $instructorId,
        Carbon|string $date,
        string $startTime,
        int $students = 1,
        ?int $exceptBookingId = null,
        ?SkillLevel $skill = null,
        ?SessionType $sessionType = null
    ): array {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        $start = $this->timeString($startTime);

        if ($start === null) {
            return [
                'is_reserved' => false,
                'reason' => 'Choose a valid start time.',
            ];
        }

        $minimumMinutes = $this->minimumLessonMinutes($sessionType, $skill);
        $minimumEnd = Carbon::createFromFormat('H:i', $start)
            ->addMinutes($minimumMinutes)
            ->format('H:i');

        if ($minimumEnd <= $start) {
            return [
                'is_reserved' => false,
                'reason' => 'This time is no longer available. Please choose a different start time.',
            ];
        }

        $overlapping = $this->bookingsInRange($instructorId, $day, $start, $minimumEnd, $exceptBookingId);

        if ($overlapping->isNotEmpty()) {
            if (($mismatch = $this->slotMismatchReason($instructorId, $day, $start, $minimumEnd, $exceptBookingId, $skill, $sessionType)) !== null) {
                return [
                    'is_reserved' => true,
                    'reason' => $mismatch,
                ];
            }

            $level = $this->instructorLevel($instructorId);
            $capacity = $this->slotCapacity($instructorId, $skill);
            $remaining = $this->remainingCapacityWithin($overlapping, $level, $capacity, $start, $minimumEnd, $skill, $sessionType);
            $requested = max(1, $students);

            if ($remaining === 0) {
                if ($overlapping->contains(fn (Booking $booking) => $booking->status === BookingStatus::InProgress)) {
                    return [
                        'is_reserved' => true,
                        'reason' => 'This time is already reserved — the instructor is currently in a session. Please choose a different start time.',
                    ];
                }

                return [
                    'is_reserved' => true,
                    'reason' => 'This time is already reserved by another student. Please choose a different start time.',
                ];
            }

            if ($remaining < $requested) {
                return [
                    'is_reserved' => true,
                    'reason' => sprintf(
                        'This time is already partially reserved — only %d %s left for your group of %d. Please choose a different start time or reduce the number of students.',
                        $remaining,
                        $remaining === 1 ? 'seat is' : 'seats are',
                        $requested
                    ),
                ];
            }
        }

        return [
            'is_reserved' => false,
            'reason' => 'This time is no longer available. Please choose a different start time.',
        ];
    }

    /**
     * Shortest lesson allowed for the requested service/skill level:
     * Teaching -> 60 mins (1h), Guiding -> 120 mins (2h), Coaching -> 120 mins (2h).
     */
    public function minimumLessonMinutes(?SessionType $sessionType = null, ?SkillLevel $skill = null): int
    {
        $service = $sessionType ?? ($skill !== null ? SessionType::forSkill($skill) : null);

        return $service?->minimumMinutes() ?? 60;
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

        // The lesson must fit AND every consecutive hour must be in open_slots.
        while ($cursor->copy()->addMinutes($duration)->lessThanOrEqualTo($finish)) {
            $value = $cursor->format('H:i');

            if ($allowed === null) {
                $slots[] = $value;
            } else {
                $rangeEnd = $cursor->copy()->addMinutes($duration);
                $probe = $cursor->copy();
                $allHoursOpen = true;

                while ($probe->lessThan($rangeEnd)) {
                    if (! isset($allowed[$probe->format('H:i')])) {
                        $allHoursOpen = false;
                        break;
                    }
                    $probe->addHour();
                }

                if ($allHoursOpen) {
                    $slots[] = $value;
                }
            }

            $cursor->addMinutes($step);
        }

        return $slots;
    }

    /**
     * Verify every 1-hour block in [start, end) falls inside the schedule's
     * working bounds and open_slots whitelist (when customized).
     *
     * @param  array{start: string, end: string, step: int, duration: int, open_slots: ?array<int, string>}  $settings
     */
    private function rangeFitsOpenSlots(string $start, string $end, array $settings): bool
    {
        if ($start < $settings['start'] || $end > $settings['end']) {
            return false;
        }

        $openSlots = $settings['open_slots'] ?? null;
        if (! is_array($openSlots)) {
            return true;
        }

        $allowed = array_flip(array_map(fn ($slot) => substr((string) $slot, 0, 5), $openSlots));
        $cursor = Carbon::createFromFormat('H:i', $start);
        $finish = Carbon::createFromFormat('H:i', $end);

        if (! $cursor || ! $finish || $cursor->greaterThanOrEqualTo($finish)) {
            return false;
        }

        while ($cursor->lessThan($finish)) {
            if (! isset($allowed[$cursor->format('H:i')])) {
                return false;
            }

            $cursor->addHour();
        }

        return true;
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
     * How far ahead a student may book, in days. Mirrors the value the booking
     * calendar uses so the UI and the server cannot disagree.
     */
    public function bookingHorizonDays(): int
    {
        return max(1, (int) config('siasurf.booking.horizon_days', 60));
    }

    /**
     * The last date that may be booked.
     */
    public function lastBookableDate(): Carbon
    {
        return today()->addDays($this->bookingHorizonDays());
    }

    /**
     * Non-null when the date sits past the booking horizon.
     *
     * The calendar already refuses to render these days, but the form is not the
     * authority - a hand-crafted POST would otherwise sail through with a date
     * months into the future, against a weekly pattern that means nothing that
     * far out. Checked here so every entry point shares the same rule.
     */
    private function beyondHorizonReason(Carbon $day): ?string
    {
        $last = $this->lastBookableDate();

        // copy() matters: Carbon objects are handles, so mutating $day here
        // would also truncate the caller's date to midnight.
        if ($day->copy()->startOfDay()->greaterThan($last)) {
            return 'Bookings open at most ' . $this->bookingHorizonDays()
                . ' days ahead. The latest date you can book is '
                . $last->format('j M Y') . '.';
        }

        return null;
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

        if (($beyondHorizon = $this->beyondHorizonReason($day)) !== null) {
            return $beyondHorizon;
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
        ?int $exceptBookingId = null,
        ?SkillLevel $skill = null,
        ?SessionType $sessionType = null,
        ?int $surfSpotId = null
    ): ?string {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);

        if ($day->isBefore(today())) {
            return 'That date is in the past.';
        }

        if (($beyondHorizon = $this->beyondHorizonReason($day)) !== null) {
            return $beyondHorizon;
        }

        $start = $this->timeString($startTime);
        $end = $this->timeString($endTime);

        if ($start === null || $end === null) {
            return 'Choose a valid start time and end time for the lesson.';
        }

        if ($end <= $start) {
            return 'The end time must be after the start time.';
        }

        [$startHour, $startMinute] = array_map('intval', explode(':', $start));
        $slotStart = $day->copy()->startOfDay()->setTime($startHour, $startMinute);

        if ($slotStart->lessThanOrEqualTo(now()->addHours(Booking::PRE_LESSON_BUFFER_HOURS))) {
            return 'Too soon to book — lessons need at least '
                . Booking::PRE_LESSON_BUFFER_HOURS . ' hour of lead time.';
        }

        $minutes = Carbon::createFromFormat('H:i', $start)
            ->diffInMinutes(Carbon::createFromFormat('H:i', $end));

        $minimumMinutes = $exceptBookingId !== null
            ? 60
            : $this->minimumLessonMinutes($sessionType, $skill);

        if ($minutes < $minimumMinutes) {
            if ($minimumMinutes > 60) {
                $service = $sessionType ?? ($skill !== null ? SessionType::forSkill($skill) : null);
                $label = $service?->label() ?? 'This';
                $minHours = (int) ($minimumMinutes / 60);

                return "{$label} sessions require a minimum of {$minHours} hours.";
            }

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

        $periods = $this->periodsFor($instructorId, $day, $students, $exceptBookingId, $skill, $sessionType);
        $verdict = $periods[$period->value] ?? null;

        if (!$verdict) {
            return 'That time of day is not a valid booking period.';
        }

        if (!$verdict['available']) {
            if (($mismatch = $this->slotMismatchReason($instructorId, $day, $start, $end, $exceptBookingId, $skill, $sessionType, $surfSpotId)) !== null) {
                return $mismatch;
            }

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
            $minimumMinutes,
            $settings['open_slots']
        );

        if (!in_array($start, $startOptions, true)) {
            return 'That start time is not available on this date.';
        }

        if (! $this->rangeFitsOpenSlots($start, $end, $settings)) {
            return 'One or more hours in the selected range are closed on this instructor\'s schedule.';
        }

        $requested = max(1, $students);
        $remaining = $this->remainingCapacity($instructorId, $day, $start, $end, $exceptBookingId, $skill, $sessionType, $surfSpotId);

        if ($remaining < $requested) {
            if (($mismatch = $this->slotMismatchReason($instructorId, $day, $start, $end, $exceptBookingId, $skill, $sessionType, $surfSpotId)) !== null) {
                return $mismatch;
            }

            return $remaining === 0
                ? 'This time slot is already reserved by another student. Please choose another time.'
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
        ?int $exceptBookingId = null,
        ?SkillLevel $skill = null,
        ?SessionType $sessionType = null,
        ?int $surfSpotId = null
    ): void {
        $reason = $this->rangeBookabilityReason(
            $instructorId,
            $date,
            $startTime,
            $endTime,
            $students,
            $exceptBookingId,
            $skill,
            $sessionType,
            $surfSpotId
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

    /**
     * Week-at-a-glance dated schedule for the Instructor Booking Calendar.
     *
     * Combines the instructor's recurring weekly slots, manual date blocks,
     * and every live slot-holding booking (including 15-minute Reserved holds)
     * across Monday–Sunday of the requested week.
     *
     * @return array<string, mixed>
     */
    public function instructorWeekSchedule(int $instructorId, Carbon|string|null $anchorDate = null): array
    {
        $this->releaseExpiredCheckoutHolds($instructorId);

        $anchor = $anchorDate ? Carbon::parse($anchorDate) : today();
        $weekStart = $anchor->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();

        $weeklyRows = Availability::query()
            ->where('user_id', $instructorId)
            ->whereNull('specific_date')
            ->get(['day_of_week', 'time_period', 'is_available', 'start_time', 'end_time', 'open_slots']);

        $hasPublishedSchedule = $weeklyRows->isNotEmpty();

        $weeklyMap = [];
        foreach ($weeklyRows as $row) {
            $dayVal = $row->day_of_week instanceof \BackedEnum
                ? (int) $row->day_of_week->value
                : (int) $row->day_of_week;
            $periodVal = $row->time_period instanceof \BackedEnum
                ? (string) $row->time_period->value
                : (string) $row->time_period;

            $weeklyMap["{$dayVal}-{$periodVal}"] = $row;
        }

        $manualBlocks = Availability::query()
            ->where('user_id', $instructorId)
            ->manualBlock()
            ->whereBetween('specific_date', [$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')])
            ->get(['specific_date', 'time_period'])
            ->groupBy(fn ($row) => $row->specific_date instanceof \DateTimeInterface
                ? $row->specific_date->format('Y-m-d')
                : (string) $row->specific_date)
            ->map(fn ($rows) => $rows->map(fn ($row) => $row->time_period instanceof \BackedEnum
                ? (string) $row->time_period->value
                : (string) $row->time_period)->all())
            ->all();

        $bookings = Booking::query()
            ->with([
                'student:id,name',
                'surfSpot:id,name',
                'waivers:id,booking_id,type',
                'payment:id,booking_id,payment_method,status',
            ])
            ->withCount('participants')
            ->where('instructor_id', $instructorId)
            ->whereBetween('date', [$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')])
            ->whereIn('status', array_map(
                fn (BookingStatus $status) => $status->value,
                BookingStatus::slotHolding()
            ))
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->groupBy(fn (Booking $booking) => $this->dateOf($booking));

        $level = $this->instructorLevel($instructorId);
        $summary = [
            'reserved' => 0,
            'pending' => 0,
            'awaiting_payment' => 0,
            'confirmed' => 0,
            'in_progress' => 0,
        ];

        $days = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $day = $weekStart->copy()->addDays($offset);
            $dateString = $day->format('Y-m-d');
            $dayOfWeek = (int) $day->format('w');
            $dayManualBlocks = $manualBlocks[$dateString] ?? [];

            $openHours = [];
            $blockedPeriods = [];

            foreach (TimePeriod::cases() as $period) {
                $periodVal = $period->value;
                $isManualBlock = in_array($periodVal, $dayManualBlocks, true);
                $blockedPeriods[$periodVal] = $isManualBlock;

                $row = $weeklyMap["{$dayOfWeek}-{$periodVal}"] ?? null;
                $periodOpen = ! $isManualBlock && (! $hasPublishedSchedule || (bool) $row?->is_available);

                if (! $periodOpen) {
                    continue;
                }

                $start = $this->timeString($row?->start_time) ?? $period->startTime();
                $end = $this->timeString($row?->end_time) ?? $period->endTime();
                if ($start >= $end) {
                    $start = $period->startTime();
                    $end = $period->endTime();
                }

                $slots = $this->slotsInRange(
                    $start,
                    $end,
                    60,
                    $this->minimumLessonMinutes(),
                    is_array($row?->open_slots) ? array_values($row->open_slots) : null
                );

                foreach ($slots as $slot) {
                    $openHours[] = $slot;
                }
            }

            $dayBookings = ($bookings[$dateString] ?? collect())->map(function (Booking $booking) use ($dateString, &$summary) {
                $range = $this->bookingRange($booking);
                $periodValue = $this->timePeriodOf($booking);
                $fallbackStart = TimePeriod::tryFrom($periodValue)?->startTime() ?? '06:00';
                $startTime = $range[0] ?? $fallbackStart;
                $endTime = $range[1] ?? Carbon::createFromFormat('H:i', $startTime)->addHours(Booking::standardHours())->format('H:i');

                $durationHours = max(
                    1,
                    (int) ($booking->duration_hours ?: Carbon::createFromFormat('H:i', $startTime)->diffInHours(Carbon::createFromFormat('H:i', $endTime)))
                );

                $isReserved = $booking->isInStudentCheckout();
                $status = $isReserved ? BookingStatus::Reserved->value : $booking->status->value;

                if (isset($summary[$status])) {
                    $summary[$status]++;
                }

                $liabilityCount = $booking->waivers
                    ->where('type', \App\Enums\WaiverType::Liability)
                    ->count();
                $requiredWaivers = max(1, (int) ($booking->participants_count ?? 1));
                $waiverSigned = $liabilityCount >= $requiredWaivers;

                $canAccept = ! $isReserved
                    && $booking->status === BookingStatus::Pending
                    && ! $booking->acceptDeadlinePassed()
                    && ! $booking->isTooCloseToStartToAccept();

                return [
                    'id' => $booking->id,
                    'booking_ref' => '#SURF-' . str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT),
                    'student_name' => $booking->student?->name ?? 'Student',
                    'surf_spot_name' => $booking->surfSpot?->name ?? 'Spot to confirm',
                    'skill_level' => $booking->skill_level instanceof \BackedEnum
                        ? (string) $booking->skill_level->value
                        : (string) $booking->skill_level,
                    'session_type' => $booking->session_type instanceof \BackedEnum
                        ? (string) $booking->session_type->value
                        : (string) ($booking->session_type ?? 'teaching'),
                    'student_count' => $this->studentsIn($booking),
                    'total_amount' => (float) ($booking->total_amount ?? 0),
                    'date' => $dateString,
                    'time_period' => $periodValue,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'duration_hours' => $durationHours,
                    'status' => $status,
                    'is_reserved' => $isReserved,
                    'waiver_signed' => $waiverSigned,
                    'payment_method' => $booking->payment?->payment_method?->value,
                    'payment_status' => $booking->payment?->status?->value,
                    'hold_remaining_seconds' => $isReserved ? $booking->getPaymentTimeRemaining() : 0,
                    'accept_remaining_seconds' => (! $isReserved && $booking->status === BookingStatus::Pending)
                        ? $booking->getAcceptTimeRemaining()
                        : 0,
                    'can_accept' => $canAccept,
                    'show_url' => route('instructor.bookings.show', $booking->id),
                ];
            })->values()->all();

            $days[] = [
                'date' => $dateString,
                'day_short' => $day->format('D'),
                'day_num' => $day->format('j'),
                'day_label' => $day->format('D, M j'),
                'is_today' => $day->isToday(),
                'manual_blocks' => $blockedPeriods,
                'open_hours' => array_values(array_unique($openHours)),
                'bookings' => $dayBookings,
            ];
        }

        return [
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekEnd->format('Y-m-d'),
            'week_label' => $weekStart->format('M j') . ' – ' . $weekEnd->format('M j, Y'),
            'prev_week' => $weekStart->copy()->subWeek()->format('Y-m-d'),
            'next_week' => $weekStart->copy()->addWeek()->format('Y-m-d'),
            'hours' => ['06:00', '07:00', '08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00'],
            'instructor_level' => $level->value,
            'slot_capacity' => $level->maxStudents(),
            'summary' => $summary,
            'days' => $days,
        ];
    }

    /**
     * Build a non-blocking warning payload when the instructor is currently teaching
     * or inside a confirmed live lesson window today (Option B).
     *
     * Suppressed when Instant Book is enabled because those requests auto-confirm
     * without waiting on a manual instructor response.
     *
     * @return array{in_session: bool, ends_at_label: ?string, message: string}|null
     */
    public function activeSessionNotice(User|int $instructor, ?Carbon $at = null): ?array
    {
        $user = $instructor instanceof User
            ? $instructor
            : User::with('instructorProfile')->find($instructor);

        if (! $user) {
            return null;
        }

        $user->loadMissing('instructorProfile');

        if ($user->instructorProfile?->usesInstantBook()) {
            return null;
        }

        $tz = config('app.timezone', 'Asia/Manila');
        $now = $at ? $at->copy()->timezone($tz) : now($tz);
        $today = $now->format('Y-m-d');

        $candidates = Booking::query()
            ->where('instructor_id', $user->id)
            ->whereDate('date', $today)
            ->whereIn('status', [
                BookingStatus::InProgress->value,
                BookingStatus::Confirmed->value,
            ])
            ->get();

        $active = $candidates->first(
            fn (Booking $booking): bool => $booking->status === BookingStatus::InProgress
        );

        if (! $active) {
            $active = $candidates->first(function (Booking $booking) use ($now): bool {
                if ($booking->status !== BookingStatus::Confirmed) {
                    return false;
                }

                $start = $booking->lessonStartAt();
                $end = $booking->lessonEndAt();

                return $start !== null
                    && $end !== null
                    && $now->greaterThanOrEqualTo($start)
                    && $now->lessThanOrEqualTo($end);
            });
        }

        if (! $active) {
            return null;
        }

        $endsAt = $active->lessonEndAt();

        if (! $endsAt && $active->started_at !== null) {
            $endsAt = $active->started_at
                ->copy()
                ->timezone($tz)
                ->addHours($active->resolvedDurationHours());
        }

        $endsAtLabel = $endsAt?->copy()->timezone($tz)->format('g:i A');
        $name = $user->name ?: 'This instructor';
        $untilPhrase = $endsAtLabel ? " (expected to finish around {$endsAtLabel})" : '';

        return [
            'in_session' => true,
            'ends_at_label' => $endsAtLabel,
            'message' => "{$name} is currently in a surf session{$untilPhrase} and may not be able to respond to your booking request right away.",
        ];
    }
}