<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\ChangeRequestStatus;
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
    public function index(Request $request): Response
    {
        $user = auth()->user();
        $status = $request->query('status');

        $bookings = Booking::with([
            'student',
            'surfSpot',
            'changeRequests',
            'payment',
            'waiver',
            'conversation' => function ($query) use ($user) {
                $query->withCount([
                    'messages as unread_count' => fn ($messages) => $messages
                        ->where('sender_id', '!=', $user->id)
                        ->whereNull('read_at'),
                ]);
            },
        ])
            ->where('instructor_id', $user->id)
            ->when(
                $status && $status !== 'all',
                fn ($query) => $query->where('status', $status)
            )
            ->orderByRaw("FIELD(status, 'pending', 'awaiting_payment', 'confirmed', 'in_progress') DESC")
            ->orderByDesc('date')
            ->paginate(10)
            ->withQueryString();

        // FR 1.9.3 - the accept countdown and FR 1.9.4 approval flag are computed
        // server side so the card renders correctly on first paint.
        $bookings->through(function (Booking $booking) {
            return array_merge($booking->toArray(), [
                'accept_time_remaining' => $booking->getAcceptTimeRemaining(),
                'can_be_declined' => $booking->isAwaitingInstructorResponse() && !$booking->acceptDeadlinePassed(),
                // Accepting has two extra guards on the service layer (window
                // closed, lesson too close to run). Surface them so the roster
                // never offers an action the server would refuse.
                'can_be_accepted' => $booking->status === BookingStatus::Pending
                    && !$booking->acceptDeadlinePassed()
                    && !$booking->isTooCloseToStartToAccept(),
                'accept_block' => match (true) {
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
                'has_waiver' => $booking->waiver !== null,
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
            ->whereIn('status', [
                BookingStatus::Confirmed,
                BookingStatus::InProgress,
                BookingStatus::Completed,
            ])
            ->count();

        return Inertia::render('Instructor/Bookings/Index', [
            'bookings' => $bookings,
            'filters' => ['status' => $status ?? 'all'],
            'attendanceDue' => $attendanceDue,
            'counts' => [
                'pending' => Booking::where('instructor_id', $user->id)->where('status', BookingStatus::Pending)->count(),
                'confirmed' => Booking::where('instructor_id', $user->id)->where('status', BookingStatus::Confirmed)->count(),
                'in_progress' => Booking::where('instructor_id', $user->id)->where('status', BookingStatus::InProgress)->count(),
                'completed' => Booking::where('instructor_id', $user->id)->where('status', BookingStatus::Completed)->count(),
            ],
        ]);
    }

    /**
     * Display single booking details for the instructor.
     */
    public function show(Booking $booking): Response
    {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        $booking->load([
            'student.studentProfile',
            'student.skillUpgradeRequests',
            'surfSpot',
            'payment',
            'waiver',
            'participants.waivers',
            'review',
            'changeRequests' => fn ($query) => $query->with(['requestedBy', 'reviewedBy'])->latest(),
        ]);

        $surfSpots = SurfSpot::where('is_active', true)->orderBy('name')->get(['id', 'name', 'difficulty']);

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

        return Inertia::render('Instructor/Bookings/Show', [
            'booking' => $booking,
            'surfSpots' => $surfSpots,
            'acceptTimeRemaining' => $booking->getAcceptTimeRemaining(),
            // Same guards as the roster: accepting is refused once the window
            // closes or the lesson is inside the pre-lesson buffer.
            'canBeAccepted' => $booking->status === BookingStatus::Pending
                && !$booking->acceptDeadlinePassed()
                && !$booking->isTooCloseToStartToAccept(),
            'acceptBlock' => match (true) {
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
            'paymentHoldMinutes' => Booking::PAYMENT_HOLD_MINUTES,
            'paymentTimeRemaining' => $booking->getPaymentTimeRemaining(),
        ]);
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

        $blockReason = $booking->startSessionBlockReason();

        if ($blockReason !== null) {
            return back()->with('error', $blockReason);
        }

        $booking->startSession();

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

        // Only in_progress bookings can be completed
        if ($booking->status !== \App\Enums\BookingStatus::InProgress) {
            return back()->with('error', 'Only active sessions can be completed.');
        }

        $booking->complete();

        // Skill Progression Logic
        if ($request->boolean('upgrade_student') && $booking->student->studentProfile) {
            $studentProfile = $booking->student->studentProfile;
            $currentLevel = $studentProfile->skill_level;
            $nextLevel = null;
            
            if ($currentLevel === \App\Enums\SkillLevel::Beginner->value) {
                $nextLevel = \App\Enums\SkillLevel::Intermediate->value;
            } elseif ($currentLevel === \App\Enums\SkillLevel::Intermediate->value) {
                $nextLevel = \App\Enums\SkillLevel::Advanced->value;
            }
            
            if ($nextLevel) {
                // Check if there is already a pending request to prevent duplicates
                $existingRequest = \App\Models\SkillUpgradeRequest::where('student_id', $booking->student_id)
                                    ->where('status', 'pending')
                                    ->first();
                                    
                if (!$existingRequest) {
                    \App\Models\SkillUpgradeRequest::create([
                        'student_id' => $booking->student_id,
                        'instructor_id' => $booking->instructor_id,
                        'booking_id' => $booking->id,
                        'current_level' => $currentLevel,
                        'requested_level' => $nextLevel,
                        'status' => 'pending'
                    ]);
                    
                    return back()->with('success', 'Session completed! A skill upgrade request has been sent to the Admin.');
                }
            }
        }

        return back()->with('success', 'Session completed! Great job.');
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
                $changeRequest = $changeService->requestCancellation(
                    $booking,
                    auth()->user(),
                    $validated['reason'] ?? null
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

        $recordable = in_array($booking->status, [
            BookingStatus::Confirmed,
            BookingStatus::InProgress,
            BookingStatus::Completed,
            BookingStatus::NoShow,
        ], true);

        if (!$recordable) {
            return back()->with('error', 'Attendance can only be recorded once the booking is confirmed.');
        }

        $validated = $request->validate([
            'attendance_status' => ['required', \Illuminate\Validation\Rule::enum(AttendanceStatus::class)],
        ]);

        $status = AttendanceStatus::from($validated['attendance_status']);

        DB::transaction(function () use ($booking, $status) {
            $booking->markAttendance($status, auth()->id());

            // A student who never arrived cannot have a session running.
            if ($status === AttendanceStatus::Absent && $booking->status === BookingStatus::InProgress) {
                $booking->complete();
            }

            broadcast(new SessionAttendanceMarked(
                $booking->fresh(['student', 'instructor']),
                $status
            ))->toOthers();
        });

        return back()->with('success', 'Attendance recorded: ' . $status->label() . '.');
    }
}
