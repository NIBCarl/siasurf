<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\PaymentStatus;
use App\Enums\SkillLevel;
use App\Enums\TimePeriod;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * FR 1.9.3 - instructors must accept or decline a new request within 2 hours.
     */
    public const ACCEPT_WINDOW_HOURS = 2;

    /**
     * FR 1.4 - the student has 15 minutes to pay once the instructor accepts.
     */
    public const PAYMENT_HOLD_MINUTES = 15;

    /**
     * FR 1.9.4 - changes made inside this window need admin approval.
     */
    public const CHANGE_APPROVAL_THRESHOLD_HOURS = 24;

    /**
     * Both response and payment windows are clamped so they always close before
     * the lesson begins. Without this, a student booking a slot one hour out
     * would hand the instructor a deadline that lands an hour AFTER the lesson
     * was due to start. The buffer keeps a little room to actually run the
     * session.
     */
    public const PRE_LESSON_BUFFER_HOURS = 1;

    /**
     * How far either side of the scheduled start a session may be started.
     */
    public const START_TOLERANCE_HOURS = 2;

    /**
     * A student may not hold more than this many unanswered requests at once.
     */
    public const MAX_OPEN_REQUESTS_PER_STUDENT = 3;

    protected $fillable = [
        'student_id',
        'instructor_id',
        'surf_spot_id',
        'date',
        'time_period',
        'start_time',
        'end_time',
        'duration_hours',
        'skill_level',
        'student_age',
        'student_count',
        'status',
        'total_amount',
        'payment_status',
        'notes',
        'has_board',
        'started_at',
        'attendance_status',
        'attendance_marked_at',
        'attendance_marked_by',
        'cancelled_at',
        'completed_at',
        'height',
        'weight',
        'payment_deadline_at',
        'accept_deadline_at',
        'accepted_at',
        'declined_at',
        'decline_reason',
        'cancellation_reason',
        'payment_timed_out_at',
    ];

    protected $casts = [
        'date' => 'date',
        'time_period' => TimePeriod::class,
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'skill_level' => SkillLevel::class,
        'status' => BookingStatus::class,
        'payment_status' => PaymentStatus::class,
        'has_board' => 'boolean',
        'duration_hours' => 'integer',
        'total_amount' => 'decimal:2',
        'student_age' => 'integer',
        'student_count' => 'integer',
        'started_at' => 'datetime',
        'attendance_status' => AttendanceStatus::class,
        'attendance_marked_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'height' => 'decimal:2',
        'weight' => 'decimal:2',
        'payment_deadline_at' => 'datetime',
        'accept_deadline_at' => 'datetime',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
        'cancellation_reason' => CancellationReason::class,
        'payment_timed_out_at' => 'datetime',
    ];

    // Relationships
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function surfSpot(): BelongsTo
    {
        return $this->belongsTo(SurfSpot::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function waiver(): HasOne
    {
        return $this->hasOne(Waiver::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function safetyIncident(): HasOne
    {
        return $this->hasOne(SafetyIncident::class);
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(BookingChangeRequest::class);
    }

    /**
     * Every location ping recorded during the live lesson, oldest first.
     */
    public function sessionLocations(): HasMany
    {
        return $this->hasMany(SessionLocation::class);
    }

    /**
     * The most recent ping. `latestOfMany()` keeps this to a single subquery
     * instead of loading the whole trail.
     */
    public function latestSessionLocation(): HasOne
    {
        return $this->hasOne(SessionLocation::class)->latestOfMany('recorded_at');
    }

    public function attendanceMarkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_marked_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', BookingStatus::Pending);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', BookingStatus::Confirmed);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', BookingStatus::Completed);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', BookingStatus::Cancelled);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('date', '>=', now()->format('Y-m-d'))
                     ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed]);
    }

    public function scopePast($query)
    {
        return $query->where('date', '<', now()->format('Y-m-d'));
    }

    public function scopeForStudent($query, int $studentId)
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeForInstructor($query, int $instructorId)
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeOnDate($query, $date)
    {
        return $query->where('date', $date);
    }

    /**
     * Requests still occupying a slot: waiting on the instructor to answer, or
     * waiting on the student to pay.
     */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', [
            BookingStatus::Pending,
            BookingStatus::AwaitingPayment,
        ]);
    }

    public function scopeForSlot($query, int $instructorId, string $date, string $timePeriod)
    {
        return $query->where('instructor_id', $instructorId)
            ->whereDate('date', $date)
            ->where('time_period', $timePeriod);
    }

    // Helper methods
    public function isPending(): bool
    {
        return $this->status === BookingStatus::Pending;
    }

    public function isConfirmed(): bool
    {
        return $this->status === BookingStatus::Confirmed;
    }

    public function isCompleted(): bool
    {
        return $this->status === BookingStatus::Completed;
    }

    public function isInProgress(): bool
    {
        return $this->status === BookingStatus::InProgress;
    }

    public function isCancelled(): bool
    {
        return $this->status === BookingStatus::Cancelled;
    }

    public function isPaid(): bool
    {
        return $this->payment_status === PaymentStatus::Completed;
    }

    public function canBeCancelled(): bool
    {
        return $this->isPending() || $this->isConfirmed() || $this->status === BookingStatus::AwaitingPayment;
    }

    /**
     * FR 1.9.3 - still waiting for the instructor to accept or decline.
     */
    public function isAwaitingInstructorResponse(): bool
    {
        return $this->status === BookingStatus::Pending;
    }

    public function isDeclined(): bool
    {
        return $this->status === BookingStatus::Declined;
    }

    public function isExpired(): bool
    {
        return $this->status === BookingStatus::Expired;
    }

    /**
     * FR 1.9.4 - changes are allowed while the booking is still changeable.
     */
    public function canBeChanged(): bool
    {
        return in_array($this->status, BookingStatus::changeable(), true);
    }

    /**
     * Seconds left for the instructor to accept. Zero once the window closes.
     */
    public function getAcceptTimeRemaining(): int
    {
        if (!$this->accept_deadline_at) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($this->accept_deadline_at, false));
    }

    public function acceptDeadlinePassed(): bool
    {
        return $this->accept_deadline_at !== null && $this->accept_deadline_at->isPast();
    }

    /**
     * Seconds left for the student to pay. Zero once the window closes.
     */
    public function getPaymentTimeRemaining(): int
    {
        if (!$this->payment_deadline_at) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($this->payment_deadline_at, false));
    }

    public function paymentDeadlinePassed(): bool
    {
        return $this->payment_deadline_at !== null && $this->payment_deadline_at->isPast();
    }

    /**
     * A cash lesson can only be settled while the lesson is still live or about
     * to be run.
     */
    public function canRecordCashPayment(): bool
    {
        return in_array($this->status, [
            BookingStatus::Confirmed,
            BookingStatus::InProgress,
        ], true) && $this->payment_status !== PaymentStatus::Completed;
    }

    /**
     * Hours until the lesson starts. Null when no start time is recorded.
     */
    public function hoursUntilLesson(): ?float
    {
        if (!$this->date) {
            return null;
        }

        $start = $this->date->copy();

        if ($this->start_time) {
            $start->setTimeFrom($this->start_time);
        }

        return now()->diffInMinutes($start, false) / 60;
    }

    /**
     * FR 1.9.4 - a lesson starting in less than 24 hours cannot be moved
     * or cancelled without admin sign-off.
     */
    public function changeRequiresAdminApproval(): bool
    {
        $hours = $this->hoursUntilLesson();

        if ($hours === null) {
            return true;
        }

        return $hours < self::CHANGE_APPROVAL_THRESHOLD_HOURS;
    }

    public function hasPendingChangeRequest(): bool
    {
        return $this->changeRequests()->pending()->exists();
    }

    public function pendingChangeRequest(): ?BookingChangeRequest
    {
        return $this->changeRequests()->pending()->latest()->first();
    }

    public function confirm(): void
    {
        $this->update([
            'status' => BookingStatus::Confirmed,
            'accepted_at' => $this->accepted_at ?? now(),
        ]);
    }

    /**
     * FR 1.9.3 - instructor turns the request down.
     */
    public function decline(?string $reason = null): void
    {
        $this->update([
            'status' => BookingStatus::Declined,
            'declined_at' => now(),
            'decline_reason' => $reason,
        ]);
    }

    /**
     * Auto-expire a request the instructor never answered within the window.
     */
    public function expireAcceptance(?string $reason = null): void
    {
        $this->update([
            'status' => BookingStatus::Expired,
            'declined_at' => now(),
            'decline_reason' => $reason ?? 'No instructor response within ' . self::ACCEPT_WINDOW_HOURS . ' hours.',
        ]);
    }

    /**
     * FR 1.10.1 - instructor confirms whether the student turned up.
     */
    public function markAttendance(AttendanceStatus $status, ?int $markedBy = null): void
    {
        $this->update([
            'attendance_status' => $status,
            'attendance_marked_at' => now(),
            'attendance_marked_by' => $markedBy,
        ]);
    }

    public function hasAttendance(): bool
    {
        return $this->attendance_status !== null;
    }

    public function studentAttended(): bool
    {
        return $this->attendance_status instanceof AttendanceStatus
            && $this->attendance_status->countsAsAttended();
    }

    /**
     * The scheduled start of the lesson, combining the date with the booked
     * start time. Used to clamp every response and payment window.
     */
    public function lessonStartAt(): ?\Carbon\Carbon
    {
        if (!$this->date) {
            return null;
        }

        $start = $this->date->copy();

        if ($this->start_time) {
            $start->setTimeFrom($this->start_time);
        } elseif ($this->time_period instanceof TimePeriod) {
            [$hour] = explode(':', $this->time_period->startTime());
            $start->setTime((int) $hour, 0);
        }

        return $start;
    }

    /**
     * Latest moment a window may stay open: just before the lesson begins.
     */
    private function clampToLessonStart(\Carbon\Carbon $deadline): \Carbon\Carbon
    {
        $lessonStart = $this->lessonStartAt();

        if (!$lessonStart) {
            return $deadline;
        }

        $cutOff = $lessonStart->copy()->subHours(self::PRE_LESSON_BUFFER_HOURS);

        return $deadline->lessThan($cutOff) ? $deadline : $cutOff;
    }

    /**
     * Deadline for the instructor response window, anchored to when the request
     * reached them and clamped so it never runs past the lesson start.
     */
    public function acceptDeadlineFrom(?\DateTimeInterface $from = null): \Carbon\Carbon
    {
        $deadline = \Carbon\Carbon::instance($from ?? now())->addHours(self::ACCEPT_WINDOW_HOURS);

        return $this->clampToLessonStart($deadline);
    }

    /**
     * Deadline for the student to complete payment once the instructor accepts.
     * FR 1.4 specifies 15 minutes, and the window always closes before the
     * lesson starts.
     */
    public function paymentDeadlineFrom(?\DateTimeInterface $from = null): \Carbon\Carbon
    {
        $deadline = \Carbon\Carbon::instance($from ?? now())->addMinutes(self::PAYMENT_HOLD_MINUTES);

        return $this->clampToLessonStart($deadline);
    }

    /**
     * Whether the booking is close enough to start that it can no longer be
     * serviceably accepted or paid for.
     */
    public function isTooCloseToStartToAccept(): bool
    {
        $lessonStart = $this->lessonStartAt();

        if (!$lessonStart) {
            return false;
        }

        return now()->greaterThanOrEqualTo(
            $lessonStart->copy()->subHours(self::PRE_LESSON_BUFFER_HOURS)
        );
    }

    public function complete(): void
    {
        $this->update([
            'status' => BookingStatus::Completed,
            'completed_at' => now(),
        ]);

        // Dispatch review request job to be sent after 2 hours
        \App\Jobs\SendReviewRequestJob::dispatch($this)
            ->delay(now()->addHours(2));
    }

    /**
     * Cancel the booking, recording WHY separately from the state. The reason is
     * no longer buried in the free-text notes field.
     */
    public function cancel(
        ?string $reason = null,
        ?CancellationReason $cancellationReason = null
    ): void {
        $this->update([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $cancellationReason,
            'notes' => $reason ? trim($this->notes . "\n\nCancellation reason: " . $reason) : $this->notes,
        ]);
    }

    /**
     * The student never paid inside the 15-minute window. This is its own
     * terminal state so the cause survives in reports instead of looking like a
     * deliberate cancellation.
     */
    public function markPaymentTimedOut(): void
    {
        $this->update([
            'status' => BookingStatus::PaymentTimeout,
            'cancelled_at' => now(),
            'payment_timed_out_at' => now(),
            'cancellation_reason' => CancellationReason::PaymentTimeout,
        ]);
    }

    /**
     * Why this session cannot be started right now, or null when it can.
     *
     * FR 1.10.1 - a lesson may only be started on the day it is scheduled, and
     * only near its booked time. Returning the reason (rather than a bare
     * boolean) lets the UI offer the right next action: reschedule, or wait.
     */
    public function startSessionBlockReason(): ?string
    {
        if ($this->status !== BookingStatus::Confirmed) {
            return 'Only confirmed bookings can be started.';
        }

        $lessonStart = $this->lessonStartAt();

        if (!$lessonStart) {
            return 'This booking has no scheduled time, so it cannot be started.';
        }

        if (!$this->date->isToday()) {
            return 'This lesson is scheduled for ' . $this->date->format('M d, Y')
                . ', not today. Reschedule it if you need to run it now.';
        }

        $tolerance = self::START_TOLERANCE_HOURS;
        $opensAt = $lessonStart->copy()->subHours($tolerance);
        $closesAt = $lessonStart->copy()->addHours($tolerance);

        if (now()->lessThan($opensAt)) {
            return 'This lesson is not due yet. You can start it from '
                . $opensAt->format('g:i A') . '.';
        }

        if (now()->greaterThan($closesAt)) {
            return 'The session window closed at ' . $closesAt->format('g:i A')
                . '. Reschedule the lesson or mark the student attendance instead.';
        }

        return null;
    }

    /**
     * Pure guard used by every entry point that starts a session.
     */
    public function canStartSession(): bool
    {
        return $this->startSessionBlockReason() === null;
    }

    public function startSession(): void
    {
        $this->update([
            'status' => BookingStatus::InProgress,
            'started_at' => now(),
        ]);
    }

    public function getSessionTimeRemaining(): int
    {
        if (!$this->isInProgress() || !$this->started_at) {
            return 0;
        }

        $endTime = $this->started_at->addHours($this->duration_hours);
        $remaining = now()->diffInSeconds($endTime, false);

        return max(0, (int) $remaining);
    }

    /**
     * Get recommended board dimensions based on student height, weight, and skill level.
     */
    public function getRecommendedBoardSize(): array
    {
        $weight = $this->weight ?? 70; // kg
        $height = $this->height ?? 170; // cm
        $skillLevel = $this->skill_level instanceof \App\Enums\SkillLevel
            ? $this->skill_level->value
            : $this->skill_level;

        $skillFactor = match ($skillLevel) {
            'beginner' => 1.1,
            'intermediate' => 0.9,
            'advanced' => 0.7,
            default => 1.0,
        };

        $volumeLiters = (int) round(($weight * $skillFactor) + 20);

        $lengthRange = match (true) {
            $height < 155 => "5'6\" – 6'0\"",
            $height < 170 => "6'0\" – 6'6\"",
            $height < 185 => "6'6\" – 7'0\"",
            default => "7'0\" – 7'6\"",
        };

        $boardType = $skillLevel === 'beginner' ? 'Soft-top / Foamie' : 'Hard-top / Shortboard';

        return [
            'volume_liters' => $volumeLiters,
            'length_range' => $lengthRange,
            'type' => $boardType,
        ];
    }
}
