<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SessionType;
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
     * The student has 15 minutes to sign the waiver and choose a payment
     * method while the slot is exclusively held for them.
     */
    public const CHECKOUT_HOLD_MINUTES = 15;

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

    /**
     * The methodology's standard lesson length, in hours. Every place that
     * needs a default duration (pricing, created end time, the session timer,
     * the UI estimate) reads this instead of hard-coding 1 or 2.
     */
    public static function standardHours(): int
    {
        $hours = (int) config('siasurf.session.standard_hours', 2);

        return $hours > 0 ? $hours : 2;
    }

    /**
     * The standard lesson length in minutes (for slot grid / fit checks).
     */
    public static function standardDurationMinutes(): int
    {
        return self::standardHours() * 60;
    }

    protected $fillable = [
        'student_id',
        'instructor_id',
        'surf_spot_id',
        'previous_surf_spot_id',
        'spot_change_reason',
        'spot_changed_at',
        'date',
        'time_period',
        'start_time',
        'end_time',
        'duration_hours',
        'skill_level',
        'session_type',
        'hourly_rate',
        'lesson_price',
        'service_fee_percent',
        'service_fee_amount',
        'student_age',
        'student_count',
        'status',
        'total_amount',
        'refund_amount',
        'instructor_retained_amount',
        'daily_ledger_id',
        'ledger_entry_type',
        'payment_status',
        'original_date',
        'is_long_term',
        'rescheduled_by_instructor_at',
        'student_accepted_reschedule_at',
        'notes',
        'has_board',
        'needs_hotel_pickup',
        'hotel_name',
        'started_at',
        'attendance_status',
        'attendance_marked_at',
        'attendance_marked_by',
        'cancelled_at',
        'completed_at',
        'completed_by',
        'height',
        'weight',
        'payment_deadline_at',
        'accept_deadline_at',
        'accepted_at',
        'declined_at',
        'decline_reason',
        'cancellation_reason',
        'cancellation_note',
        'payment_timed_out_at',
        'last_event_at',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'time_period' => TimePeriod::class,
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'skill_level' => SkillLevel::class,
        'session_type' => SessionType::class,
        'hourly_rate' => 'decimal:2',
        'lesson_price' => 'decimal:2',
        'service_fee_percent' => 'decimal:2',
        'service_fee_amount' => 'decimal:2',
        'status' => BookingStatus::class,
        'payment_status' => PaymentStatus::class,
        'has_board' => 'boolean',
        'needs_hotel_pickup' => 'boolean',
        'duration_hours' => 'integer',
        'total_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'instructor_retained_amount' => 'decimal:2',
        'original_date' => 'date:Y-m-d',
        'is_long_term' => 'boolean',
        'rescheduled_by_instructor_at' => 'datetime',
        'student_accepted_reschedule_at' => 'datetime',
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
        'last_event_at' => 'datetime',
        'spot_changed_at' => 'datetime',
    ];

    /**
     * Human-readable cancellation cause, appended to every serialized booking
     * so the student list and detail page can show it without duplicating the
     * enum labels in JavaScript.
     *
     * @var list<string>
     */
    protected $appends = ['cancellation_label'];

    protected function cancellationLabel(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->cancellation_reason?->label());
    }

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

    public function previousSurfSpot(): BelongsTo
    {
        return $this->belongsTo(SurfSpot::class, 'previous_surf_spot_id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->latest('id');
    }

    public function latestRefund(): HasOne
    {
        return $this->hasOne(Refund::class)->latestOfMany();
    }

    public function waiver(): HasOne
    {
        return $this->hasOne(Waiver::class);
    }

    /**
     * Everyone surfing in this booking. The primary participant is the account
     * holder; companions are declared by the booker.
     */
    public function participants(): HasMany
    {
        return $this->hasMany(BookingParticipant::class)->orderBy('id');
    }

    public function primaryParticipant(): HasOne
    {
        return $this->hasOne(BookingParticipant::class)
            ->where('role', BookingParticipant::ROLE_PRIMARY);
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(Waiver::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /**
     * Private booking-scoped chat thread (FR 1.7).
     */
    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    public function safetyIncident(): HasOne
    {
        return $this->hasOne(SafetyIncident::class);
    }

    public function safetyIncidents(): HasMany
    {
        return $this->hasMany(SafetyIncident::class);
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(BookingChangeRequest::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function latestEvent(): HasOne
    {
        return $this->hasOne(BookingEvent::class)->ofMany([
            'occurred_at' => 'max',
            'id' => 'max',
        ]);
    }

    public function attendanceMarkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_marked_by');
    }

    public function dailyLedger(): BelongsTo
    {
        return $this->belongsTo(InstructorDailyLedger::class, 'daily_ledger_id');
    }

    // Scopes
    public function scopeReserved($query)
    {
        return $query->where('status', BookingStatus::Reserved);
    }

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
     * Requests still occupying a slot: held during checkout, waiting on the
     * instructor to answer, or waiting on the student to pay.
     */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', [
            BookingStatus::Reserved,
            BookingStatus::Pending,
            BookingStatus::AwaitingPayment,
        ]);
    }

    /**
     * Bookings currently in the student's 15-minute waiver/payment-method
     * checkout hold (slot is occupied, but not yet sent to the instructor).
     */
    public function scopeInStudentCheckout($query)
    {
        return $query->where(function ($q) {
            $q->where('status', BookingStatus::Reserved)
                ->orWhere(function ($legacy) {
                    $legacy->where('status', BookingStatus::Pending)
                        ->whereNull('accept_deadline_at')
                        ->whereNotNull('payment_deadline_at')
                        ->whereDoesntHave('payment');
                });
        });
    }

    /**
     * Bookings that have completed the student's waiver & payment-method step
     * and are actionable by the instructor.
     */
    public function scopeSubmittedToInstructor($query)
    {
        return $query->where('status', '!=', BookingStatus::Reserved)
            ->where(function ($q) {
                $q->where('status', '!=', BookingStatus::Pending)
                    ->orWhereNotNull('accept_deadline_at')
                    ->orWhereNull('payment_deadline_at')
                    ->orWhereHas('payment');
            });
    }

    public function scopeForSlot($query, int $instructorId, string $date, string $timePeriod)
    {
        return $query->where('instructor_id', $instructorId)
            ->whereDate('date', $date)
            ->where('time_period', $timePeriod);
    }

    // Helper methods
    public function isReserved(): bool
    {
        return $this->status === BookingStatus::Reserved || $this->isInStudentCheckout();
    }

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

    /**
     * Whether a GCash receipt screenshot is currently awaiting verification.
     */
    public function hasPendingReceiptVerification(): bool
    {
        $payment = $this->payment;

        return $payment !== null && $payment->status === PaymentStatus::PendingVerification;
    }

    /**
     * Whether the instructor rescheduled (or proposed to reschedule) this booking
     * and the student has not yet agreed to the new date (which entitles the
     * student to a full refund of the lesson price if they decline or cancel).
     */
    public function hasPendingRescheduleResponse(): bool
    {
        return ($this->rescheduled_by_instructor_at !== null
            && $this->student_accepted_reschedule_at === null)
            || $this->hasOpenRescheduleProposal();
    }

    public function pendingRescheduleProposal(): ?BookingChangeRequest
    {
        if ($this->relationLoaded('changeRequests')) {
            return $this->changeRequests
                ->where('type', \App\Enums\ChangeRequestType::Reschedule)
                ->where('status', \App\Enums\ChangeRequestStatus::AwaitingStudent)
                ->sortByDesc('id')
                ->first();
        }

        return $this->changeRequests()
            ->reschedule()
            ->awaitingStudent()
            ->latest('id')
            ->first();
    }

    public function hasOpenRescheduleProposal(): bool
    {
        return $this->pendingRescheduleProposal() !== null;
    }

    public function isRescheduled(): bool
    {
        return $this->rescheduled_by_instructor_at !== null;
    }

    /**
     * Effective lesson price (excluding the non-refundable 7% service fee).
     */
    public function effectiveLessonPrice(): float
    {
        if ($this->lesson_price !== null) {
            return round((float) $this->lesson_price, 2);
        }

        $fee = (float) ($this->service_fee_amount ?? 0);

        return round(max(0.0, (float) $this->total_amount - $fee), 2);
    }

    /**
     * Effective platform service fee retained on cancellations.
     */
    public function effectiveServiceFeeAmount(): float
    {
        return round((float) ($this->service_fee_amount ?? 0), 2);
    }

    /**
     * Original scheduled date locked at booking creation for long-term vs.
     * short-term refund policy classification.
     */
    public function effectiveOriginalDate(): ?\Carbon\Carbon
    {
        return $this->original_date ?? $this->date;
    }

    /**
     * Whether the student has already handed over money or a GCash proof of
     * payment.
     */
    public function hasSubmittedPayment(): bool
    {
        if ($this->payment_status === PaymentStatus::Completed) {
            return true;
        }

        $payment = $this->payment;

        if (! $payment) {
            return false;
        }

        return in_array($payment->status, PaymentStatus::submitted(), true)
            || $payment->receipt_path !== null;
    }

    /**
     * Students may withdraw pending/awaiting-payment requests OR cancel
     * confirmed paid bookings under the tiered refund policy. Unverified
     * GCash screenshots (`pending_verification`) and underpaid balances remain
     * blocked until verified/settled.
     */
    public function canBeCancelled(): bool
    {
        if ($this->hasPendingReceiptVerification()) {
            return false;
        }

        if ($this->payment_status === PaymentStatus::Underpaid || $this->payment?->status === PaymentStatus::Underpaid) {
            return false;
        }

        return $this->isReserved() || $this->isPending() || $this->isConfirmed() || $this->status === BookingStatus::AwaitingPayment;
    }

    /**
     * Active bookings whose surf spot can still be updated by the instructor
     * (once out of the student's 15-minute checkout hold and before close).
     */
    public function canChangeSurfSpot(): bool
    {
        if ($this->isInStudentCheckout()) {
            return false;
        }

        return in_array($this->status, [
            BookingStatus::Pending,
            BookingStatus::AwaitingPayment,
            BookingStatus::Confirmed,
            BookingStatus::InProgress,
        ], true);
    }

    /**
     * FR 1.9.3 - still waiting for the instructor to accept or decline.
     */
    public function isAwaitingInstructorResponse(): bool
    {
        return $this->status === BookingStatus::Pending && ! $this->isInStudentCheckout();
    }

    /**
     * Whether the booking is still in the student's 15-minute waiver/payment
     * selection hold before being sent to the instructor.
     */
    public function isInStudentCheckout(): bool
    {
        if ($this->status === BookingStatus::Reserved) {
            return true;
        }

        if ($this->status !== BookingStatus::Pending) {
            return false;
        }

        if ($this->accept_deadline_at !== null || $this->payment_deadline_at === null) {
            return false;
        }

        return $this->relationLoaded('payment')
            ? $this->payment === null
            : ! $this->payment()->exists();
    }

    /**
     * Whether the 15-minute student waiver/checkout hold has expired.
     */
    public function checkoutHoldExpired(): bool
    {
        return $this->isInStudentCheckout()
            && $this->payment_deadline_at !== null
            && $this->payment_deadline_at->isPast();
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
     * A cash lesson can be settled by the instructor or admin once confirmed,
     * while in progress, or after session completion.
     */
    public function canRecordCashPayment(): bool
    {
        $payment = $this->relationLoaded('payment')
            ? $this->getRelation('payment')
            : $this->payment()->first();

        if ($payment && $payment->payment_method !== PaymentMethod::Cash) {
            return false;
        }

        return in_array($this->status, [
            BookingStatus::Confirmed,
            BookingStatus::InProgress,
            BookingStatus::Completed,
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
        return $this->changeRequests()->open()->exists();
    }

    public function pendingChangeRequest(): ?BookingChangeRequest
    {
        return $this->changeRequests()->open()->latest('id')->first();
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
     * start time in the platform timezone (Asia/Manila).
     */
    public function lessonStartAt(): ?\Carbon\Carbon
    {
        if (!$this->date) {
            return null;
        }

        $tz = config('app.timezone', 'Asia/Manila');
        $start = \Carbon\Carbon::parse($this->date->format('Y-m-d'), $tz)->startOfDay();

        if ($this->start_time) {
            $start->setTime($this->start_time->hour, $this->start_time->minute, 0);
        } elseif ($this->time_period instanceof TimePeriod) {
            [$hour] = explode(':', $this->time_period->startTime());
            $start->setTime((int) $hour, 0, 0);
        }

        return $start;
    }

    /**
     * The scheduled end of the lesson in Asia/Manila.
     */
    public function lessonEndAt(): ?\Carbon\Carbon
    {
        $start = $this->lessonStartAt();

        if (! $start) {
            return null;
        }

        $tz = config('app.timezone', 'Asia/Manila');

        if ($this->end_time) {
            $end = \Carbon\Carbon::parse($this->date->format('Y-m-d'), $tz)
                ->setTime($this->end_time->hour, $this->end_time->minute, 0);

            if ($end->greaterThan($start)) {
                return $end;
            }
        }

        $hours = max(1, (int) ($this->duration_hours ?: self::standardHours()));

        return $start->copy()->addHours($hours);
    }

    /**
     * Resolve the scheduled lesson length in whole hours.
     *
     * Prefers the span between start_time and end_time when both are present so
     * bookings whose duration_hours column still holds a schema default stay accurate.
     */
    public function resolvedDurationHours(): int
    {
        if ($this->start_time && $this->end_time) {
            $start = \Carbon\Carbon::createFromFormat('H:i', $this->start_time->format('H:i'));
            $end = \Carbon\Carbon::createFromFormat('H:i', $this->end_time->format('H:i'));

            if ($end->greaterThan($start)) {
                $minutes = $start->diffInMinutes($end);
                if ($minutes >= 60 && $minutes % 60 === 0) {
                    return (int) ($minutes / 60);
                }
            }
        }

        return max(1, (int) ($this->duration_hours ?: self::standardHours()));
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
     * Deadline for the student to complete the waiver and payment-method step
     * while the slot is held for them (15 minutes).
     */
    public function checkoutDeadlineFrom(?\DateTimeInterface $from = null): \Carbon\Carbon
    {
        $deadline = \Carbon\Carbon::instance($from ?? now())->addMinutes(self::CHECKOUT_HOLD_MINUTES);

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

    /**
     * Complete the lesson, recording WHO closed the session alongside when.
     *
     * `completed_by` mirrors `attendance_marked_by` so the finished-session
     * record shows the same actor trail for both instructor actions.
     */
    public function complete(?int $completedBy = null): void
    {
        $this->update([
            'status' => BookingStatus::Completed,
            'completed_at' => now(),
            'completed_by' => $completedBy,
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
        ?CancellationReason $cancellationReason = null,
        ?float $refundAmount = null,
        ?float $instructorRetainedAmount = null,
    ): void {
        $attributes = [
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $cancellationReason,
            // Kept separate from `notes` (the student's special requests) so the
            // student can actually read why the lesson was cancelled.
            'cancellation_note' => $reason ? trim($reason) : null,
            'payment_deadline_at' => null,
            'accept_deadline_at' => null,
        ];

        if ($refundAmount !== null) {
            $attributes['refund_amount'] = round($refundAmount, 2);
        }

        if ($instructorRetainedAmount !== null) {
            $attributes['instructor_retained_amount'] = round($instructorRetainedAmount, 2);
        }

        $this->update($attributes);
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
            'cancellation_note' => $this->cancellation_note
                ?: 'The payment window expired before the payment was completed, so the slot was released.',
        ]);
    }

    /**
     * Why attendance cannot be marked right now, or null when it is allowed.
     *
     * Instructors cannot mark attendance before the scheduled start time.
     */
    public function attendanceBlockReason(): ?string
    {
        if (! in_array($this->status, [
            BookingStatus::Confirmed,
            BookingStatus::InProgress,
            BookingStatus::Completed,
            BookingStatus::NoShow,
        ], true)) {
            return 'Attendance can only be recorded once the booking is confirmed.';
        }

        // Live or already-closed sessions can always have attendance recorded/updated.
        if (in_array($this->status, [BookingStatus::InProgress, BookingStatus::Completed, BookingStatus::NoShow], true)) {
            return null;
        }

        $lessonStart = $this->lessonStartAt();

        if (! $lessonStart) {
            return 'This booking has no scheduled time, so attendance cannot be marked.';
        }

        $tz = config('app.timezone', 'Asia/Manila');

        if (now($tz)->lessThan($lessonStart)) {
            return 'Attendance cannot be marked yet. Scheduled for '
                . $lessonStart->format('M d, Y \a\t g:i A') . '.';
        }

        return null;
    }

    public function canMarkAttendance(): bool
    {
        return $this->attendanceBlockReason() === null;
    }

    /**
     * Why this session cannot be completed yet, or null when it can.
     */
    public function completeSessionBlockReason(): ?string
    {
        if ($this->status === BookingStatus::InProgress) {
            return null;
        }

        if ($this->status === BookingStatus::Confirmed) {
            $lessonStart = $this->lessonStartAt();
            $tz = config('app.timezone', 'Asia/Manila');

            if (! $lessonStart || now($tz)->lessThan($lessonStart)) {
                return 'The scheduled session time has not started yet.';
            }

            if (! $this->studentAttended()) {
                return 'Mark student attendance as Present or Late before completing the session.';
            }

            return null;
        }

        return 'Only active or attended confirmed sessions can be completed.';
    }

    public function canCompleteSession(): bool
    {
        return $this->completeSessionBlockReason() === null;
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
