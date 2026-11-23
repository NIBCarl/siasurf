<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\ChangeRequestStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\BookingDeclined;
use App\Exceptions\BookingChangeException;
use App\Exceptions\BookingRequestException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Notifications\BookingDeclinedNotification;
use App\Services\AvailabilityService;
use App\Services\BookingAcceptanceService;
use App\Services\BookingChangeService;
use App\Services\ReceiptService;
use App\Events\SessionAttendanceMarked;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    /**
     * Display a listing of bookings for the instructor.
     */
    public function index(Request $request, AvailabilityService $availabilityService): Response
    {
        $user = auth()->user();
        $availabilityService->releaseExpiredCheckoutHolds($user->id);

        $status = $request->query('status');

        $bookings = Booking::with([
            'student',
            'surfSpot',
            'changeRequests',
            'payment',
            'waiver',
            'waivers',
            'conversation' => function ($query) use ($user) {
                $query->withCount([
                    'messages as unread_count' => fn ($messages) => $messages
                        ->where('sender_id', '!=', $user->id)
                        ->whereNull('read_at'),
                ]);
            },
        ])
            ->withCount('participants')
            ->where('instructor_id', $user->id)
            ->when(
                $status === 'reserved',
                fn ($query) => $query->inStudentCheckout(),
                fn ($query) => $query->when(
                    $status === 'pending',
                    fn ($q) => $q->submittedToInstructor()->where('status', BookingStatus::Pending),
                    fn ($q) => $q->when(
                        $status && $status !== 'all',
                        fn ($inner) => $inner->where('status', $status)
                    )
                )
            )
            ->orderByRaw("FIELD(status, 'reserved', 'pending', 'awaiting_payment', 'confirmed', 'in_progress') DESC")
            ->orderByDesc('date')
            ->paginate(10)
            ->withQueryString();

        // FR 1.9.3 - the accept countdown and FR 1.9.4 approval flag are computed
        // server side so the card renders correctly on first paint.
        $bookings->through(function (Booking $booking) {
            $isReserved = $booking->isInStudentCheckout();
            $requiredWaivers = max(1, (int) ($booking->participants_count ?? 1));
            $liabilitySignedCount = $booking->waivers
                ->where('type', \App\Enums\WaiverType::Liability)
                ->count();
            $hasWaiver = $liabilitySignedCount > 0
                ? $liabilitySignedCount >= $requiredWaivers
                : $booking->waiver !== null;

            return array_merge($booking->toArray(), [
                'status' => $isReserved ? BookingStatus::Reserved->value : $booking->status->value,
                'is_reserved' => $isReserved,
                'hold_time_remaining' => $isReserved ? $booking->getPaymentTimeRemaining() : 0,
                'accept_time_remaining' => $isReserved ? 0 : $booking->getAcceptTimeRemaining(),
                'can_be_declined' => $booking->isAwaitingInstructorResponse() && !$booking->acceptDeadlinePassed(),
                // Accepting has two extra guards on the service layer (window
                // closed, lesson too close to run). Surface them so the roster
                // never offers an action the server would refuse.
                'can_be_accepted' => !$isReserved
                    && $booking->status === BookingStatus::Pending
                    && !$booking->acceptDeadlinePassed()
                    && !$booking->isTooCloseToStartToAccept(),
                'accept_block' => match (true) {
                    $isReserved => 'awaiting_waiver',
                    $booking->status !== BookingStatus::Pending => null,
                    $booking->acceptDeadlinePassed() => 'window_closed',
                    $booking->isTooCloseToStartToAccept() => 'too_close',
                    default => null,
                },
                'requires_admin_approval_for_change' => $booking->changeRequiresAdminApproval(),
                'has_pending_change_request' => $booking->changeRequests
                    ->where('status', ChangeRequestStatus::Pending)
                    ->isNotEmpty(),
                'attendance_label' => $booking->attendance_status?->label(),
                'attendance_status' => $booking->attendance_status?->value,
                'has_waiver' => $hasWaiver,
                'payment_method' => $booking->payment?->payment_method?->value,
                // Only recommend a board when the student actually supplied the
                // measurements; otherwise the model would fall back to defaults.
                'board_recommendation' => ($booking->height && $booking->weight)
                    ? $booking->getRecommendedBoardSize()
                    : null,
                'chat_unread_count' => (int) ($booking->conversation?->unread_count ?? 0),
            ]);
        });

        $attendanceDue = Booking::where('instructor_id', $user->id)
            ->whereNull('attendance_status')
            ->where(function ($query) {
                $query->whereIn('status', [
                    BookingStatus::InProgress,
                    BookingStatus::Completed,
                ])->orWhere(function ($confirmed) {
                    $confirmed->where('status', BookingStatus::Confirmed)
                        ->whereDate('date', '<=', today());
                });
            })
            ->get(['id', 'status', 'date', 'start_time'])
            ->filter(fn (Booking $b) => $b->status !== BookingStatus::Confirmed
                || $b->lessonStartAt() === null
                || $b->lessonStartAt()->lessThanOrEqualTo(now()))
            ->count();

        return Inertia::render('Instructor/Bookings/Index', [
            'bookings' => $bookings,
            'filters' => ['status' => $status ?? 'all'],
            'attendanceDue' => $attendanceDue,
            'counts' => [
                'all' => Booking::where('instructor_id', $user->id)->count(),
                'reserved' => Booking::where('instructor_id', $user->id)->inStudentCheckout()->count(),
                'pending' => Booking::where('instructor_id', $user->id)->submittedToInstructor()->where('status', BookingStatus::Pending)->count(),
                'confirmed' => Booking::where('instructor_id', $user->id)->where('status', BookingStatus::Confirmed)->count(),
                'in_progress' => Booking::where('instructor_id', $user->id)->where('status', BookingStatus::InProgress)->count(),
                'completed' => Booking::where('instructor_id', $user->id)->where('status', BookingStatus::Completed)->count(),
            ],
        ]);
    }

    /**
     * Display single booking details for the instructor.
     */
    public function show(Booking $booking, AvailabilityService $availabilityService): Response
    {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        if ($booking->checkoutHoldExpired()) {
            $booking->expireAcceptance('Waiver and checkout hold expired after ' . Booking::CHECKOUT_HOLD_MINUTES . ' minutes.');
            $availabilityService->releaseForBooking($booking);
            $booking->refresh();
        }

        $booking->load([
            'student.studentProfile',
            'student.latestSkillAssessment',
            'student.skillUpgradeRequests',
            'instructor.instructorProfile.surfSpots',
            'surfSpot',
            'previousSurfSpot',
            'payment',
            'latestRefund',
            'refunds',
            'waiver',
            'participants.waivers',
            'review',
            'changeRequests' => fn ($query) => $query->with(['requestedBy', 'reviewedBy'])->latest(),
        ]);

        $sessionGroup = $availabilityService->overlappingSessionBookings($booking);
        $groupSkills = $sessionGroup->map(fn (Booking $b) => $b->skill_level ?? \App\Enums\SkillLevel::Beginner->value);
        $effectiveSkill = $groupSkills->isNotEmpty()
            ? \App\Enums\SkillLevel::mostRestrictive($groupSkills)
            : ($booking->skill_level instanceof \App\Enums\SkillLevel
                ? $booking->skill_level
                : (\App\Enums\SkillLevel::tryFrom((string) $booking->skill_level) ?? \App\Enums\SkillLevel::Beginner));

        $profile = $booking->instructor?->instructorProfile;
        $surfSpots = $profile
            ? $profile->bookableSurfSpotsFor($effectiveSkill)
                ->map(fn (SurfSpot $spot) => [
                    'id' => $spot->id,
                    'name' => $spot->name,
                    'difficulty' => $spot->difficulty,
                    'area' => $spot->area,
                ])
                ->values()
                ->all()
            : SurfSpot::where('is_active', true)->orderBy('name')->get(['id', 'name', 'difficulty', 'area']);

        // Surface the same board maths the roster and the student flow use, so
        // the detail page never re-implements the recommendation client side.
        $booking->setAttribute(
            'board_recommendation',
            ($booking->height && $booking->weight) ? $booking->getRecommendedBoardSize() : null,
        );

        $booking->participants->each(fn ($participant) => $participant->setAttribute(
            'board_match',
            $participant->getRecommendedBoardSize(),
        ));

        // The roster computes this in the controller too; keep the label in one shape.
        $booking->setAttribute('attendance_label', $booking->attendance_status?->label());

        $studentAssessment = $booking->student?->latestSkillAssessment
            ? app(\App\Services\SkillAssessmentService::class)->formatForDisplay($booking->student->latestSkillAssessment)
            : null;

        $isReserved = $booking->isInStudentCheckout();

        return Inertia::render('Instructor/Bookings/Show', [
            'booking' => $booking,
            'studentAssessment' => $studentAssessment,
            'surfSpots' => $surfSpots,
            'isReserved' => $isReserved,
            'holdTimeRemaining' => $isReserved ? $booking->getPaymentTimeRemaining() : 0,
            'acceptTimeRemaining' => $isReserved ? 0 : $booking->getAcceptTimeRemaining(),
            // Same guards as the roster: accepting is refused once the window
            // closes or the lesson is inside the pre-lesson buffer.
            'canBeAccepted' => !$isReserved
                && $booking->status === BookingStatus::Pending
                && !$booking->acceptDeadlinePassed()
                && !$booking->isTooCloseToStartToAccept(),
            'acceptBlock' => match (true) {
                $isReserved => 'awaiting_waiver',
                $booking->status !== BookingStatus::Pending => null,
                $booking->acceptDeadlinePassed() => 'window_closed',
                $booking->isTooCloseToStartToAccept() => 'too_close',
                default => null,
            },
            'requiresAdminApprovalForChange' => $booking->changeRequiresAdminApproval(),
            'hoursUntilLesson' => $booking->hoursUntilLesson(),
            'changeApprovalThresholdHours' => Booking::CHANGE_APPROVAL_THRESHOLD_HOURS,
            // FR 1.10.1 - why "Start Session" is unavailable, or null when it is.
            'startBlockReason' => $booking->startSessionBlockReason(),
            'canStartSession' => $booking->canStartSession(),
            'canChangeSurfSpot' => $booking->canChangeSurfSpot(),
            'sharedSessionCount' => $sessionGroup->where('status', '!=', BookingStatus::Reserved)->count(),
            'paymentHoldMinutes' => Booking::PAYMENT_HOLD_MINUTES,
            'paymentTimeRemaining' => $isReserved ? 0 : $booking->getPaymentTimeRemaining(),
        ]);
    }

    /**
     * Update the surf spot for this session (and any shared-slot bookings)
     * with a required reason sent to every affected student.
     */
    public function updateSurfSpot(
        Request $request,
        Booking $booking,
        BookingChangeService $changeService
    ): RedirectResponse {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'surf_spot_id' => 'required|integer|exists:surf_spots,id',
            'reason' => 'required|string|min:5|max:500',
        ], [
            'reason.required' => 'Tell your student(s) why the surf location is changing.',
            'reason.min' => 'Please enter at least 5 characters explaining the surf spot change.',
        ]);

        $spot = SurfSpot::findOrFail($validated['surf_spot_id']);

        try {
            $updatedGroup = $changeService->updateSurfSpot(
                $booking,
                $spot,
                $validated['reason'],
                auth()->user()
            );
        } catch (BookingChangeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $notifiedCount = $updatedGroup->where('status', '!=', BookingStatus::Reserved)->count();
        $message = $notifiedCount > 1
            ? "Surf spot updated to {$spot->name}. All {$notifiedCount} bookings in this shared session were updated and notified."
            : "Surf spot updated to {$spot->name} and the student has been notified.";

        return back()->with('success', $message);
    }

    /**
     * Confirm a booking request (FR 1.9.3).
     *
     * All of the GCash/cash work lives in BookingAcceptanceService so Instant
     * Book produces exactly the same result.
     */
    public function confirm(Booking $booking, BookingAcceptanceService $acceptanceService)
    {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        try {
            $accepted = $acceptanceService->accept($booking);
        } catch (BookingRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Could not accept this booking: ' . $exception->getMessage());
        }

        if ($accepted->status === BookingStatus::AwaitingPayment) {
            return back()->with(
                'success',
                'Booking accepted. The student has '
                . Booking::PAYMENT_HOLD_MINUTES . ' minutes to pay.'
            );
        }

        return back()->with('success', 'Booking confirmed successfully.');
    }

    /**
     * Start a surfing session (FR 1.10.1).
     *
     * The date/time rule lives on the Booking model so it cannot be bypassed
     * from any other entry point.
     */
    public function startSession(Booking $booking)
    {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        $error = DB::transaction(function () use ($booking): ?string {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            $blockReason = $locked->startSessionBlockReason();
            if ($blockReason !== null) {
                return $blockReason;
            }

            $locked->startSession();

            return null;
        });

        if ($error !== null) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'Session started! Timer is now active.');
    }

    /**
     * Complete a surfing session.
     */
    public function completeSession(Request $request, Booking $booking)
    {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        $cashReceived = $request->boolean('cash_received');
        $upgradeStudent = $request->boolean('upgrade_student');

        $result = DB::transaction(function () use ($booking, $cashReceived, $upgradeStudent): array {
            $locked = Booking::with(['payment', 'student.studentProfile'])
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== BookingStatus::InProgress) {
                return ['status' => 'error', 'message' => 'Only active sessions can be completed.'];
            }

            $locked->complete(auth()->id());

            // If this is an unpaid Cash on Hand booking and the instructor confirmed
            // cash collection in the completion modal, record the cash payment and
            // generate the student's receipt immediately.
            if ($cashReceived && $locked->canRecordCashPayment()) {
                $this->recordCashPayment($locked);
            }

            // Skill Progression Logic
            if ($upgradeStudent && $locked->student?->studentProfile) {
                $studentProfile = $locked->student->studentProfile;
                $currentLevel = $studentProfile->skill_level;
                $nextLevel = null;

                if ($currentLevel === \App\Enums\SkillLevel::Beginner->value) {
                    $nextLevel = \App\Enums\SkillLevel::Intermediate->value;
                } elseif ($currentLevel === \App\Enums\SkillLevel::Intermediate->value) {
                    $nextLevel = \App\Enums\SkillLevel::Advanced->value;
                }

                if ($nextLevel) {
                    $existingRequest = \App\Models\SkillUpgradeRequest::where('student_id', $locked->student_id)
                        ->where('status', 'pending')
                        ->first();

                    if (! $existingRequest) {
                        \App\Models\SkillUpgradeRequest::create([
                            'student_id' => $locked->student_id,
                            'instructor_id' => $locked->instructor_id,
                            'booking_id' => $locked->id,
                            'current_level' => $currentLevel,
                            'requested_level' => $nextLevel,
                            'status' => 'pending',
                        ]);

                        return ['status' => 'success', 'message' => 'Session completed! A skill upgrade request has been sent to the Admin.'];
                    }
                }
            }

            return ['status' => 'success', 'message' => 'Session completed! Great job.'];
        });

        return back()->with($result['status'], $result['message']);
    }
    /**
     * Request a standalone skill upgrade for a completed session.
     */
    public function requestUpgrade(Request $request, Booking $booking)
    {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        if ($booking->status !== \App\Enums\BookingStatus::Completed) {
            return back()->with('error', 'Only completed sessions are eligible for a standalone upgrade request.');
        }

        if (!$booking->student->studentProfile) {
            return back()->with('error', 'Student profile not found.');
        }

        $currentLevel = $booking->student->studentProfile->skill_level;
        $nextLevel = null;

        if ($currentLevel === \App\Enums\SkillLevel::Beginner->value) {
            $nextLevel = \App\Enums\SkillLevel::Intermediate->value;
        } elseif ($currentLevel === \App\Enums\SkillLevel::Intermediate->value) {
            $nextLevel = \App\Enums\SkillLevel::Advanced->value;
        }

        if (!$nextLevel) {
            return back()->with('error', 'Student is already at the highest level.');
        }

        $existingRequest = \App\Models\SkillUpgradeRequest::where('student_id', $booking->student_id)
                            ->where('status', 'pending')
                            ->exists();

        if ($existingRequest) {
            return back()->with('error', 'A skill upgrade request is already pending for this student.');
        }

        \App\Models\SkillUpgradeRequest::create([
            'student_id' => $booking->student_id,
            'instructor_id' => $booking->instructor_id,
            'booking_id' => $booking->id,
            'current_level' => $currentLevel,
            'requested_level' => $nextLevel,
            'status' => 'pending'
        ]);

        return back()->with('success', 'Skill upgrade request submitted to admin for approval.');
    }

    /**
     * FR 1.9.3 - decline a booking request inside the 2 hour response window.
     */
    public function decline(Request $request, Booking $booking): RedirectResponse
    {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        if ($booking->isInStudentCheckout()) {
            return back()->with('error', 'The student is still completing the waiver and payment step for this booking.');
        }

        if (!$booking->isAwaitingInstructorResponse()) {
            return back()->with('error', 'This booking request has already been handled.');
        }

        if ($booking->acceptDeadlinePassed()) {
            return back()->with('error', 'The 2 hour response window has closed, so this request was released automatically.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'Tell the student why you cannot take this lesson.',
            'reason.min' => 'Please give a slightly more descriptive reason (at least 3 characters).',
        ]);

        // Stored on the booking so the student sees exactly why it was declined.
        $reason = $validated['reason'];

        DB::transaction(function () use ($booking, $reason) {
            $booking->decline($reason);

            // FR 1.8.3 - hand the slot back in case an accept had already locked it.
            app(AvailabilityService::class)->releaseForBooking($booking);

            broadcast(new BookingDeclined(
                $booking->fresh(['student', 'instructor', 'surfSpot']),
                $reason
            ))->toOthers();
        });

        // FR 1.9.3 - the student is told straight away and can rebook.
        $booking->student?->notify(new BookingDeclinedNotification($booking, false));

        return back()->with('success', 'Booking request declined. The student has been notified.');
    }

    /**
     * FR 1.9.4 - cancel or reschedule a booking. Changes filed less than
     * 24 hours before the lesson are parked for SISA approval instead of
     * being applied immediately.
     */
    public function requestChange(Request $request, Booking $booking, BookingChangeService $changeService): RedirectResponse
    {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:cancellation,reschedule'],
            'reason' => ['nullable', 'string', 'max:500'],
            'cancellation_reason' => ['nullable', 'string', 'in:instructor,weather'],
            'date' => ['nullable', 'required_if:type,reschedule', 'date', 'after_or_equal:today'],
            'time_period' => ['nullable', 'required_if:type,reschedule', 'string', 'in:morning,afternoon'],
            'start_time' => ['nullable', 'required_if:type,reschedule', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_if:type,reschedule', 'date_format:H:i', 'after:start_time'],
        ], [
            'date.required_if' => 'Please choose the new lesson date.',
            'date.after_or_equal' => 'The new lesson date must be today or later.',
            'time_period.required_if' => 'Please choose morning or afternoon.',
            'end_time.after' => 'The end time must be after the start time.',
        ]);

        try {
            if ($validated['type'] === \App\Enums\ChangeRequestType::Cancellation->value) {
                $cause = ($validated['cancellation_reason'] ?? null) === 'weather'
                    ? \App\Enums\CancellationReason::Weather
                    : \App\Enums\CancellationReason::Instructor;

                $changeRequest = $changeService->requestCancellation(
                    $booking,
                    auth()->user(),
                    $validated['reason'] ?? null,
                    $cause
                );
            } else {
                $changeRequest = $changeService->requestReschedule(
                    $booking,
                    auth()->user(),
                    [
                        'date' => $validated['date'],
                        'time_period' => $validated['time_period'],
                        'start_time' => $validated['start_time'],
                        'end_time' => $validated['end_time'],
                    ],
                    $validated['reason'] ?? null
                );
            }
        } catch (BookingChangeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($changeRequest->requires_admin_approval) {
            return back()->with(
                'success',
                'Your request needs SISA approval because the lesson starts in under '
                . Booking::CHANGE_APPROVAL_THRESHOLD_HOURS . ' hours. It will apply once approved.'
            );
        }

        return back()->with('success', ucfirst($changeRequest->type->value) . ' applied successfully.');
    }

    /**
     * FR 1.9.4 - withdraw a change request that SISA has not decided on yet.
     */
    public function cancelChangeRequest(Booking $booking): RedirectResponse
    {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        $pending = $booking->changeRequests()
            ->pending()
            ->where('requested_by', auth()->id())
            ->latest()
            ->first();

        if (!$pending) {
            return back()->with('error', 'There is no pending change request to withdraw.');
        }

        $pending->delete();

        return back()->with('success', 'Change request withdrawn. The booking keeps its original slot.');
    }

    /**
     * FR 1.10.1 - mark whether the student turned up for the lesson.
     */
    public function markAttendance(Request $request, Booking $booking): RedirectResponse
    {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'attendance_status' => ['required', \Illuminate\Validation\Rule::enum(AttendanceStatus::class)],
        ]);

        $status = AttendanceStatus::from($validated['attendance_status']);
        $cashReceived = $request->boolean('cash_received');

        $error = DB::transaction(function () use ($booking, $status, $cashReceived): ?string {
            $locked = Booking::with(['payment', 'student', 'instructor', 'surfSpot'])
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            $recordable = in_array($locked->status, [
                BookingStatus::Confirmed,
                BookingStatus::InProgress,
                BookingStatus::Completed,
                BookingStatus::NoShow,
            ], true);

            if (! $recordable) {
                return 'Attendance can only be recorded once the booking is confirmed.';
            }

            // Records attendance_status / attendance_marked_at / attendance_marked_by.
            $locked->markAttendance($status, auth()->id());

            // Cash on Hand: settle payment before transitioning an Absent session
            // into NoShow (so payment receipt generation sees a recordable booking).
            if ($cashReceived && $locked->canRecordCashPayment()) {
                $this->recordCashPayment($locked);
                $locked->refresh();
            }

            $lessonStart = $locked->lessonStartAt();
            $windowClosed = $lessonStart !== null
                && now()->greaterThan($lessonStart->copy()->addHours(Booking::START_TOLERANCE_HOURS));

            // A student who never arrived on a live session or after the start
            // tolerance closes transitions to NoShow (never Completed).
            if ($status === AttendanceStatus::Absent && (
                $locked->status === BookingStatus::InProgress
                || ($locked->status === BookingStatus::Confirmed && $windowClosed)
            )) {
                $payment = $locked->payment;
                $retainedAmount = ($payment && $payment->payment_method === PaymentMethod::GCash && $locked->isPaid())
                    ? $locked->effectiveLessonPrice()
                    : 0.0;

                $locked->update([
                    'status' => BookingStatus::NoShow,
                    'ended_at' => $locked->ended_at ?? now(),
                    'completed_at' => null,
                    'cancellation_reason' => CancellationReason::StudentNoShow,
                    'cancellation_note' => $locked->cancellation_note ?: 'Student did not arrive for the scheduled lesson.',
                    'refund_amount' => 0.0,
                    'instructor_retained_amount' => $retainedAmount,
                ]);

                DB::afterCommit(function () use ($locked, $retainedAmount) {
                    $fresh = $locked->fresh(['student', 'instructor', 'surfSpot']);
                    $fresh?->student?->notify(new \App\Notifications\BookingNoShowNotification($fresh, $retainedAmount));
                });
            } elseif ($locked->status === BookingStatus::Confirmed && $windowClosed && $status->countsAsAttended()) {
                // Reconcile past-window Confirmed lessons so student feedback opens cleanly.
                $locked->complete(auth()->id());
            }

            DB::afterCommit(function () use ($locked, $status) {
                broadcast(new SessionAttendanceMarked(
                    $locked->fresh(['student', 'instructor']),
                    $status
                ))->toOthers();
            });

            return null;
        });

        if ($error !== null) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'Attendance recorded: ' . $status->label() . '.');
    }

    /**
     * Settle an unpaid Cash on Hand booking and issue the student receipt.
     *
     * Extracted from markAttendance(), which previously carried two verbatim
     * copies of this block, one of them nested inside the "student absent"
     * branch so cash was only ever considered for absences.
     */
    private function recordCashPayment(Booking $booking): void
    {
        $settlement = [
            'amount' => $booking->total_amount,
            'lesson_price' => $booking->effectiveLessonPrice(),
            'service_fee_amount' => $booking->effectiveServiceFeeAmount(),
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
            'receipt_verified_by' => auth()->id(),
            'receipt_verified_at' => now(),
        ];

        if ($booking->payment) {
            $booking->payment->update($settlement + [
                'notes' => $booking->payment->notes ?: 'Cash collected and verified upon session completion.',
            ]);
        } else {
            $booking->payment()->create($settlement + [
                'payment_method' => PaymentMethod::Cash,
                'notes' => 'Cash collected and verified upon session completion.',
            ]);
        }

        $booking->update(['payment_status' => PaymentStatus::Completed]);

        try {
            app(ReceiptService::class)->generateReceipt($booking);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
