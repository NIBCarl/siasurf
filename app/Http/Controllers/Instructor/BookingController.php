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
use App\Models\BookingChangeRequest;
use App\Models\SkillUpgradeRequest;
use App\Models\SurfSpot;
use App\Models\User;
use App\Notifications\BookingDeclinedNotification;
use App\Notifications\SessionAttendanceNotification;
use App\Notifications\SessionCompletedNotification;
use App\Notifications\SkillUpgradeRequestedNotification;
use App\Services\AvailabilityService;
use App\Services\BookingAcceptanceService;
use App\Services\BookingChangeService;
use App\Services\BookingEventService;
use App\Services\EarningsLedgerService;
use App\Services\ReceiptService;
use App\Events\SessionAttendanceMarked;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            ->orderByRaw(
                'CASE status WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 WHEN ? THEN 3 WHEN ? THEN 4 ELSE 5 END ASC',
                [
                    BookingStatus::Pending->value,
                    BookingStatus::InProgress->value,
                    BookingStatus::AwaitingPayment->value,
                    BookingStatus::Confirmed->value,
                    BookingStatus::Reserved->value,
                ]
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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
            $proposal = $booking->pendingRescheduleProposal();

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
                    ->contains(fn (BookingChangeRequest $cr) => $cr->isOpen()),
                'has_pending_reschedule_proposal' => $proposal !== null,
                'pending_reschedule_proposal' => $proposal?->toProposalPayload(),
                'is_rescheduled' => $booking->isRescheduled(),
                'can_mark_attendance' => $booking->canMarkAttendance(),
                'attendance_block_reason' => $booking->attendanceBlockReason(),
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
                'has_conversation' => $booking->conversation !== null,
            ]);
        });

        $attendanceDue = Booking::where('instructor_id', $user->id)
            ->whereIn('status', [
                BookingStatus::Confirmed,
                BookingStatus::InProgress,
                BookingStatus::Completed,
            ])
            ->whereNull('attendance_status')
            ->get()
            ->filter(fn (Booking $booking) => $booking->canMarkAttendance())
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
            'conversation',
            'changeRequests' => fn ($query) => $query->with(['requestedBy', 'reviewedBy'])->latest('id'),
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
        $proposal = $booking->pendingRescheduleProposal();

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
            'canMarkAttendance' => $booking->canMarkAttendance(),
            'attendanceBlockReason' => $booking->attendanceBlockReason(),
            'canCompleteSession' => $booking->canCompleteSession(),
            'completeBlockReason' => $booking->completeSessionBlockReason(),
            'hasConversation' => $booking->conversation !== null,
            'isRescheduled' => $booking->isRescheduled(),
            'pendingRescheduleProposal' => $proposal?->toProposalPayload(),
            'canChangeSurfSpot' => $booking->canChangeSurfSpot(),
            'sharedSessionCount' => $sessionGroup->where('status', '!=', BookingStatus::Reserved)->count(),
            'paymentHoldMinutes' => Booking::PAYMENT_HOLD_MINUTES,
            'paymentTimeRemaining' => $isReserved ? 0 : $booking->getPaymentTimeRemaining(),
        ]);
    }

    /**
     * Automated available start times for rescheduling a specific booking.
     */
    public function rescheduleAvailability(
        Request $request,
        Booking $booking,
        AvailabilityService $availabilityService
    ): JsonResponse {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        return response()->json(
            $availabilityService->rescheduleStartTimes($booking, $validated['date'])
        );
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
            $accepted = $acceptanceService->accept($booking, auth()->user());
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

            $blockReason = $locked->completeSessionBlockReason();
            if ($blockReason !== null) {
                return ['status' => 'error', 'message' => $blockReason];
            }

            $locked->complete(auth()->id());
            app(BookingEventService::class)->recordCompleted($locked, auth()->id());

            DB::afterCommit(function () use ($locked): void {
                $completed = $locked->fresh(['student', 'instructor', 'surfSpot']);
                if (! $completed?->student) {
                    return;
                }

                try {
                    $completed->student->notify(new SessionCompletedNotification($completed));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to notify student of completed session.', [
                        'booking_id' => $completed->id,
                        'student_id' => $completed->student_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

            // If this is an unpaid Cash on Hand booking and the instructor confirmed
            // cash collection in the completion modal, record the cash payment and
            // generate the student's receipt immediately.
            if ($cashReceived && $locked->canRecordCashPayment()) {
                $this->recordCashPayment($locked);
            }

            app(EarningsLedgerService::class)->syncBookingToLedger($locked->fresh(['payment']));

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
                        $upgradeRequest = SkillUpgradeRequest::create([
                            'student_id' => $locked->student_id,
                            'instructor_id' => $locked->instructor_id,
                            'booking_id' => $locked->id,
                            'current_level' => $currentLevel,
                            'requested_level' => $nextLevel,
                            'status' => 'pending',
                        ]);

                        DB::afterCommit(fn () => $this->notifyAdminsOfSkillUpgrade(
                            $upgradeRequest->fresh(['student', 'instructor', 'booking'])
                        ));

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

        $upgradeRequest = SkillUpgradeRequest::create([
            'student_id' => $booking->student_id,
            'instructor_id' => $booking->instructor_id,
            'booking_id' => $booking->id,
            'current_level' => $currentLevel,
            'requested_level' => $nextLevel,
            'status' => 'pending'
        ]);

        $this->notifyAdminsOfSkillUpgrade($upgradeRequest->fresh(['student', 'instructor', 'booking']));

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
     * FR 1.9.4 - cancel or propose a reschedule for a booking.
     *
     * Reschedules accept ONLY date, start_time, and reason; time_period and
     * end_time are derived automatically, the reason is sent to Messages, and
     * the booking only moves once the student agrees (plus SISA if < 24h).
     */
    public function requestChange(Request $request, Booking $booking, BookingChangeService $changeService): RedirectResponse
    {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:cancellation,reschedule'],
            'reason' => ['nullable', 'required_if:type,reschedule', 'string', 'min:3', 'max:500'],
            'cancellation_reason' => ['nullable', 'string', 'in:instructor,weather'],
            'date' => ['nullable', 'required_if:type,reschedule', 'date', 'after_or_equal:today'],
            'start_time' => ['nullable', 'required_if:type,reschedule', 'date_format:H:i'],
        ], [
            'reason.required_if' => 'Please enter a reason so the student receives it in Messages before agreeing.',
            'reason.min' => 'Please give a short explanation (at least 3 characters) for the student.',
            'date.required_if' => 'Please choose the new lesson date.',
            'date.after_or_equal' => 'The new lesson date must be today or later.',
            'start_time.required_if' => 'Please choose an available start time.',
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
                        'start_time' => $validated['start_time'],
                    ],
                    $validated['reason'] ?? null
                );
            }
        } catch (BookingChangeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($changeRequest->isReschedule()) {
            return back()->with(
                'success',
                'Reschedule request sent to ' . ($booking->student?->name ?? 'the student') . ' and posted in Messages. The schedule will update once the student agrees.'
            );
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
     * FR 1.9.4 - withdraw a change request that the student or SISA has not decided on yet.
     */
    public function cancelChangeRequest(Booking $booking): RedirectResponse
    {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        $pending = $booking->changeRequests()
            ->open()
            ->where('requested_by', auth()->id())
            ->latest('id')
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

            $blockReason = $locked->attendanceBlockReason();
            if ($blockReason !== null) {
                return $blockReason;
            }

            $eventService = app(BookingEventService::class);

            // Records attendance_status / attendance_marked_at / attendance_marked_by.
            $locked->markAttendance($status, auth()->id());
            $eventService->recordAttendanceMarked($locked, $status, auth()->id());

            // Cash on Hand: settle payment before transitioning an Absent session
            // into NoShow (so payment receipt generation sees a recordable booking).
            if ($cashReceived && $locked->canRecordCashPayment()) {
                $this->recordCashPayment($locked);
                $locked->refresh();
            }

            $tz = config('app.timezone', 'Asia/Manila');
            $lessonEnd = $locked->lessonEndAt();

            if ($status->countsAsAttended()) {
                if ($locked->status === BookingStatus::Confirmed) {
                    if ($lessonEnd && now($tz)->greaterThanOrEqualTo($lessonEnd)) {
                        $locked->complete(auth()->id());
                        $eventService->recordCompleted($locked, auth()->id());
                    } else {
                        $locked->update([
                            'status' => BookingStatus::InProgress,
                            'started_at' => $locked->started_at ?? now(),
                        ]);
                    }
                }
            } elseif ($status === AttendanceStatus::Absent) {
                if (in_array($locked->status, [BookingStatus::Confirmed, BookingStatus::InProgress], true)) {
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
                }
            }

            app(EarningsLedgerService::class)->syncBookingToLedger($locked->fresh(['payment']));

            DB::afterCommit(function () use ($locked, $status) {
                $fresh = $locked->fresh(['student', 'instructor']);

                if (! $fresh) {
                    return;
                }

                try {
                    broadcast(new SessionAttendanceMarked($fresh, $status))->toOthers();
                } catch (\Throwable $exception) {
                    Log::warning('Failed to broadcast attendance marked.', [
                        'booking_id' => $fresh->id,
                        'error' => $exception->getMessage(),
                    ]);
                }

                $fresh->student?->notify(new SessionAttendanceNotification($fresh, $status));
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

        app(BookingEventService::class)->recordPaymentCompleted(
            $booking,
            $booking->payment?->fresh(),
            auth()->id()
        );

        DB::afterCommit(function () use ($booking) {
            $fresh = $booking->fresh(['student', 'instructor', 'surfSpot', 'payment']);
            if (! $fresh) {
                return;
            }

            try {
                if ($fresh->payment) {
                    broadcast(new \App\Events\PaymentReceived($fresh->payment))->toOthers();
                }
            } catch (\Throwable $exception) {
                Log::warning('Failed to broadcast cash settlement.', [
                    'booking_id' => $booking->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            app(ReceiptService::class)->sendPaymentReceipts($fresh);
        });
    }

    private function notifyAdminsOfSkillUpgrade(?SkillUpgradeRequest $upgradeRequest): void
    {
        if (! $upgradeRequest) {
            return;
        }

        User::query()->admins()->get()->each(function (User $admin) use ($upgradeRequest): void {
            try {
                $admin->notify(new SkillUpgradeRequestedNotification($upgradeRequest));
            } catch (\Throwable $exception) {
                Log::warning('Failed to notify admin of skill upgrade request.', [
                    'skill_upgrade_request_id' => $upgradeRequest->id,
                    'admin_id' => $admin->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }
}
