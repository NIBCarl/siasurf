<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\PaymentStatus;
use App\Enums\SkillLevel;
use App\Events\BookingCancelled;
use App\Events\BookingCreated;
use App\Exceptions\BookingRequestException;
use App\Exceptions\SafetyViolationException;
use App\Jobs\ExpireBookingAcceptanceJob;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\SurfSpot;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use App\Services\AvailabilityService;
use App\Services\BookingAcceptanceService;
use App\Services\BookingRequestGuard;
use App\Services\PricingService;
use App\Services\SafetyService;
use App\Services\StormglassService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    protected SafetyService $safetyService;

    protected PricingService $pricingService;

    protected StormglassService $stormglassService;

    protected BookingRequestGuard $requestGuard;

    protected BookingAcceptanceService $acceptanceService;

    protected AvailabilityService $availabilityService;

    public function __construct(
        SafetyService $safetyService,
        PricingService $pricingService,
        StormglassService $stormglassService,
        BookingRequestGuard $requestGuard,
        BookingAcceptanceService $acceptanceService,
        AvailabilityService $availabilityService
    ) {
        $this->safetyService = $safetyService;
        $this->pricingService = $pricingService;
        $this->stormglassService = $stormglassService;
        $this->requestGuard = $requestGuard;
        $this->acceptanceService = $acceptanceService;
        $this->availabilityService = $availabilityService;
    }

    /**
     * Display the booking creation form (Step 1: Select instructor)
     */
    public function create(Request $request, User $instructor): Response
    {
        Gate::authorize('create', Booking::class);

        if (! $instructor->isBookable()) {
            return redirect()->route('instructors.search')->with('error', 'This instructor is currently not available for bookings.');
        }

        // Skill-level matching also applies to the booking form itself: a
        // beginner-only instructor must not be reachable by an advanced
        // student through a direct URL.
        $viewerSkill = auth()->user()->studentProfile?->skill_level;
        $viewerSkill = $viewerSkill instanceof SkillLevel
            ? $viewerSkill
            : SkillLevel::tryFrom((string) $viewerSkill);

        abort_if(
            $viewerSkill !== null
                && ! $viewerSkill->canBeTaughtBy($instructor->instructorProfile->level->value),
            404
        );

        $instructor->load(['instructorProfile', 'availabilities']);

        // Get safety rules applicable to this instructor
        $safetyRules = $this->safetyService->getApplicableRules(
            $instructor->instructorProfile->level->value
        );

        // Get available surf spots based on the student's own level. An
        // intermediate surfer may use beginner/intermediate breaks but never an
        // advanced one; the front end narrows this further for the party.
        // Outer/inner sections are separate records, so order the picker by
        // entry difficulty and then by the name students will recognise.
        $studentSkill = $viewerSkill ?? SkillLevel::Beginner;

        $surfSpots = SurfSpot::where('is_active', true)
            ->whereIn('difficulty', $studentSkill->allowedSurfSpotDifficulties())
            ->orderByRaw("CASE difficulty WHEN 'beginner' THEN 1 WHEN 'intermediate' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get();

        return Inertia::render('Booking/Create', [
            'instructor' => $instructor,
            'surfSpots' => $surfSpots,
            'safetyRules' => $safetyRules,
            // Explains why some breaks are not in the list.
            'surfSpotRule' => $studentSkill->surfSpotRuleLabel(),
            'allowedSurfSpotDifficulties' => $studentSkill->allowedSurfSpotDifficulties(),
            'tideData' => $this->stormglassService->getUpcomingTides(7),
            // Fallback lesson length for the estimate before a date is chosen;
            // once a date is picked the exact per-slot duration comes from the
            // availability API.
            'defaultDurationMinutes' => Booking::standardDurationMinutes(),
            // The account holder's level is the source of truth (set at signup
            // and maintained by the skill-upgrade flow) - not re-asked here.
            'studentSkillLevel' => auth()->user()->studentProfile?->skill_level ?? SkillLevel::Beginner->value,
            'studentName' => auth()->user()->name,
            'step' => 1,
        ]);
    }

    /**
     * Store booking details (Step 2: Enter details)
     */
    public function storeDetails(Request $request, User $instructor): Response|RedirectResponse
    {
        Gate::authorize('create', Booking::class);

        if (! $instructor->isBookable()) {
            return redirect()->route('instructors.search')->with('error', 'This instructor is currently not available for bookings.');
        }

        $validated = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'start_time' => [
                'required',
                'date_format:H:i',
                function ($attribute, $value, $fail) use ($request) {
                    $dateTime = Carbon::parse($request->date.' '.$value);
                    if ($dateTime->isPast()) {
                        $fail('The '.$attribute.' must be in the future.');
                    }
                },
            ],
            // The student now chooses both ends of the lesson. The server still
            // re-validates the range against the instructor's schedule and the
            // slot's remaining student capacity. Older clients that only send a
            // start time fall back to the instructor's standard lesson length.
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            // Primary skill is derived from the profile; still accepted for
            // backwards compatibility with older clients.
            'skill_level' => 'nullable|in:beginner,intermediate,advanced',
            'student_age' => 'required|integer|min:5|max:100',
            // Height and weight exist only to recommend a board, so they are
            // not asked for students who bring their own board.
            'height' => [
                'nullable',
                'numeric',
                'min:50',
                'max:250',
                \Illuminate\Validation\Rule::requiredIf(fn () => empty($request->input('participants'))
                    && ! $request->boolean('has_board')),
            ],
            'weight' => [
                'nullable',
                'numeric',
                'min:10',
                'max:200',
                \Illuminate\Validation\Rule::requiredIf(fn () => empty($request->input('participants'))
                    && ! $request->boolean('has_board')),
            ],
            'student_count' => 'required|integer|min:1|max:10',
            'surf_spot_id' => 'required|exists:surf_spots,id',
            'has_board' => 'required|boolean',
            'notes' => 'nullable|string|max:1000',

            // Everyone surfing. When present the first entry is the account
            // holder; the rest are declared companions.
            'participants' => 'nullable|array|max:10',
            'participants.*.full_name' => 'required|string|max:255',
            'participants.*.age' => 'required|integer|min:5|max:100',
            'participants.*.height' => 'nullable|numeric|min:50|max:250',
            'participants.*.weight' => 'nullable|numeric|min:10|max:200',
            'participants.*.skill_level' => 'required|in:beginner,intermediate,advanced',
            'participants.*.has_board' => 'required|boolean',
            'participants.*.emergency_contact_name' => 'nullable|string|max:255',
            'participants.*.emergency_contact_phone' => 'nullable|string|max:50',
            'participants.*.medical_info' => 'nullable|string|max:1000',
            'participants.*.guardian_name' => 'nullable|string|max:255',
            'participants.*.guardian_phone' => 'nullable|string|max:50',
        ], [
            'end_time.after' => 'The end time must be after the start time.',
            'height.required' => 'Height is needed to recommend a board. Tick “I have my own board” if you do not need one.',
            'weight.required' => 'Weight is needed to recommend a board. Tick “I have my own board” if you do not need one.',
        ]);

        // Per-participant board-size fields follow the same rule: only required
        // when that surfer does not have their own board.
        foreach (($request->input('participants') ?? []) as $index => $participant) {
            $hasBoard = filter_var($participant['has_board'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (! $hasBoard && (blank($participant['height'] ?? null) || blank($participant['weight'] ?? null))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "participants.{$index}.height" => 'Height and weight are required when this surfer does not have a board.',
                ]);
            }
        }

        // Determine the time period from the chosen start, and derive the
        // lesson length from the two ends the student picked. When an older
        // client sends only a start time, the instructor's configured lesson
        // length is used so nothing silently changes for them.
        $startTime = Carbon::parse($validated['start_time']);
        $validated['time_period'] = $startTime->hour < 12 ? 'morning' : 'afternoon';

        $endTime = ! empty($validated['end_time'])
            ? Carbon::parse($validated['end_time'])
            : $startTime->copy()->addMinutes(
                $this->availabilityService->sessionMinutesFor(
                    $instructor->id,
                    $validated['date'],
                    $validated['time_period']
                )
            );

        if ($endTime->lessThanOrEqualTo($startTime)) {
            return back()->withErrors(['end_time' => 'The end time must be after the start time.']);
        }

        $validated['end_time'] = $endTime->format('H:i');
        $durationMinutes = (int) $startTime->diffInMinutes($endTime);
        $validated['duration_hours'] = (int) round($durationMinutes / 60);

        // Resolve who is surfing. The account holder's skill level always comes
        // from their profile (the source of truth) - never from the form.
        $profileLevel = auth()->user()->studentProfile?->skill_level;
        $primarySkill = $profileLevel instanceof SkillLevel
            ? $profileLevel->value
            : ($profileLevel ?: ($validated['skill_level'] ?? SkillLevel::Beginner->value));

        // Height/weight may be intentionally absent when the student brings a
        // board, so normalise them before the row is written.
        $validated['height'] = $validated['height'] ?? null;
        $validated['weight'] = $validated['weight'] ?? null;

        $participantsInput = $validated['participants'] ?? null;
        $hasExplicitParticipants = ! empty($participantsInput);

        if ($hasExplicitParticipants) {
            // Index 0 is the account holder; override any client-supplied level.
            $participantsInput[0]['skill_level'] = $primarySkill;
            $validated['student_count'] = count($participantsInput);

            $primary = $participantsInput[0];
            $validated['skill_level'] = $primarySkill;
            $validated['student_age'] = $primary['age'];
            $validated['height'] = $primary['height'] ?? null;
            $validated['weight'] = $primary['weight'] ?? null;
            $validated['has_board'] = $primary['has_board'];
        } else {
            // Legacy single-person payload: synthesise the primary participant.
            $participantsInput = [[
                'full_name' => auth()->user()->name,
                'age' => $validated['student_age'],
                'height' => $validated['height'] ?? null,
                'weight' => $validated['weight'] ?? null,
                'skill_level' => $primarySkill,
                'has_board' => $validated['has_board'],
            ]];
            $validated['skill_level'] = $primarySkill;
        }

        // Skill-level matching (BR-3.6): the directory already hides
        // instructors that cannot teach this student, and this guard makes sure
        // a hand-crafted request cannot bypass that rule either.
        $instructorLevelValue = $instructor->instructorProfile->level->value;
        $studentSkill = SkillLevel::tryFrom($primarySkill) ?? SkillLevel::Beginner;

        if (! $studentSkill->canBeTaughtBy($instructorLevelValue)) {
            return back()->with('error', $studentSkill->label()
                . ' lessons need a ' . $studentSkill->instructorLevelRangeLabel()
                . '. This instructor is Level ' . $instructorLevelValue . '.');
        }

        // Get surf spot for validation
        $surfSpot = SurfSpot::findOrFail($validated['surf_spot_id']);

        // Check tide conditions for the selected date/time
        $tideWarning = $this->stormglassService->getTideWarning(
            $validated['date'],
            $validated['start_time'],
            $durationMinutes
        );

        // Step 3: Safety validation. With an explicit party, every participant
        // is checked (most restrictive wins); otherwise use the legacy
        // single-person rules so old clients keep working.
        try {
            if ($hasExplicitParticipants) {
                $this->safetyService->validateParticipants(
                    $participantsInput,
                    $instructorLevelValue,
                    $surfSpot->difficulty,
                    auth()->id()
                );
            } else {
                $this->safetyService->validateBookingSafety([
                    'instructor_level' => $instructorLevelValue,
                    'student_count' => $validated['student_count'],
                    'skill_level' => $validated['skill_level'],
                    'student_age' => $validated['student_age'],
                    'surf_spot_difficulty' => $surfSpot->difficulty,
                ], auth()->id());
            }
        } catch (SafetyViolationException $e) {
            return back()->with('error', $e->getMessage());
        }

        // FR 1.8 - the instructor's own availability must be respected, and the
        // exact range must still have room for this party. This replaces the
        // old "any overlap is a conflict" check, which made Level 3 group
        // bookings impossible.
        try {
            $this->availabilityService->assertRangeIsBookable(
                $instructor->id,
                $validated['date'],
                $validated['start_time'],
                $validated['end_time'],
                $validated['student_count']
            );
        } catch (BookingRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        // Calculate pricing using the same duration the booking will run for.
        $pricing = $this->pricingService->calculatePrice(
            $instructor->instructorProfile,
            $validated['student_count'],
            [],
            null,
            $validated['duration_hours']
        );

        // Abuse guard: block a duplicate request for the same slot, and cap how
        // many unanswered requests one student can hold.
        try {
            $this->requestGuard->assertStudentMayRequest(auth()->user(), [
                'instructor_id' => $instructor->id,
                'date' => $validated['date'],
                'time_period' => $validated['time_period'],
            ]);
        } catch (BookingRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        // Create the booking and its participants atomically. The instructor
        // row is locked so two students cannot both pass the capacity check and
        // create overlapping requests in the same instant.
        try {
            $booking = DB::transaction(function () use ($validated, $instructor, $pricing, $participantsInput) {
                User::query()->whereKey($instructor->id)->lockForUpdate()->first();

                // Re-check under the lock: the first transaction may have taken
                // the last seat while this request was waiting.
                $this->availabilityService->assertRangeIsBookable(
                    $instructor->id,
                    $validated['date'],
                    $validated['start_time'],
                    $validated['end_time'],
                    $validated['student_count']
                );

                $this->requestGuard->assertStudentMayRequest(auth()->user(), [
                    'instructor_id' => $instructor->id,
                    'date' => $validated['date'],
                    'time_period' => $validated['time_period'],
                ]);

                $booking = Booking::create([
                'student_id' => auth()->id(),
                'instructor_id' => $instructor->id,
                'surf_spot_id' => $validated['surf_spot_id'],
                'date' => $validated['date'],
                'time_period' => $validated['time_period'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'duration_hours' => $validated['duration_hours'],
                'skill_level' => $validated['skill_level'],
                'student_age' => $validated['student_age'],
                'height' => $validated['height'],
                'weight' => $validated['weight'],
                'student_count' => $validated['student_count'],
                'has_board' => $validated['has_board'],
                'status' => BookingStatus::Pending,
                'total_amount' => $pricing['total'],
                'payment_status' => PaymentStatus::Pending,
                'notes' => $validated['notes'] ?? null,
            ]);

                foreach ($participantsInput as $index => $participant) {
                    $booking->participants()->create([
                        'role' => $index === 0
                            ? BookingParticipant::ROLE_PRIMARY
                            : BookingParticipant::ROLE_COMPANION,
                        'full_name' => $participant['full_name'] ?? null,
                        'age' => $participant['age'] ?? null,
                        'height' => $participant['height'] ?? null,
                        'weight' => $participant['weight'] ?? null,
                        'skill_level' => $participant['skill_level'] ?? SkillLevel::Beginner->value,
                        'has_board' => (bool) ($participant['has_board'] ?? false),
                        'emergency_contact_name' => $participant['emergency_contact_name'] ?? null,
                        'emergency_contact_phone' => $participant['emergency_contact_phone'] ?? null,
                        'medical_info' => $participant['medical_info'] ?? null,
                        'guardian_name' => $participant['guardian_name'] ?? null,
                        'guardian_phone' => $participant['guardian_phone'] ?? null,
                    ]);
                }

                // Start the acceptance clock now. Without a deadline, a booking
                // that is abandoned at the waiver/payment-method step sits in
                // `pending` (which holds the slot) and the stale sweep - which
                // only looks at rows that HAVE a deadline - can never release it.
                $booking->update(['accept_deadline_at' => $booking->acceptDeadlineFrom()]);

                // Level 1 requests own the hour immediately, even before the
                // instructor answers, so the same slot cannot be double-booked.
                // Level 2 is full at one student; Level 3 only locks once its
                // fifth seat is taken - otherwise it stays open for more.
                $this->availabilityService->lockIfSlotIsFull($booking);

                return $booking;
            });
        } catch (BookingRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $redirect = redirect()->route('bookings.waiver', $booking->id)
            ->with('success', 'Details saved. Please sign the waiver.');

        if ($tideWarning) {
            $redirect = $redirect->with('tideWarning', $tideWarning);
        }

        return $redirect;
    }

    /**
     * Display payment page (Step 5: Payment)
     */
    public function payment(Booking $booking): Response|RedirectResponse
    {
        Gate::authorize('view', $booking);

        if ($booking->status === BookingStatus::AwaitingPayment) {
            return redirect()->route('payments.show', $booking)
                ->with('info', 'Your instructor accepted this booking. Complete the GCash payment from this page before the hold expires.');
        }

        if ($booking->status !== BookingStatus::Pending) {
            return redirect()->route('student.bookings.show', $booking)
                ->with('info', 'This booking has already moved past the payment-method step.');
        }

        return Inertia::render('Booking/Payment', [
            'booking' => $booking->load(['instructor', 'surfSpot']),
            'step' => 5,
        ]);
    }

    /**
     * Process payment (Step 5: Payment)
     */
    public function storePayment(Request $request, Booking $booking): RedirectResponse
    {
        // The student owns the booking; the explicit status guard below prevents
        // this endpoint from regressing an accepted/confirmed booking.
        Gate::authorize('view', $booking);

        if ($booking->status === BookingStatus::AwaitingPayment) {
            return redirect()->route('payments.show', $booking)
                ->with('info', 'Payment method is already set. Complete the GCash payment from the payment page.');
        }

        if ($booking->status !== BookingStatus::Pending) {
            return redirect()->route('student.bookings.show', $booking)
                ->with('error', 'The payment method can no longer be changed for this booking.');
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:gcash,cash',
        ]);

        // Instant Book: a trusted instructor's calendar confirms immediately, so
        // the student skips the 2-hour acceptance wait entirely.
        $instantBook = (bool) $booking->instructor?->instructorProfile?->usesInstantBook();

        DB::transaction(function () use ($booking, $validated, $instantBook) {
            // Update booking status
            $booking->update([
                'payment_status' => $validated['payment_method'] === 'cash'
                    ? PaymentStatus::PendingCash
                    : PaymentStatus::Pending,
                'status' => BookingStatus::Pending, // Status remains pending until confirmed or paid
                // FR 1.9.3 - the instructor now has 2 hours to accept or decline.
                'accept_deadline_at' => $booking->acceptDeadlineFrom(),
            ]);

            // Create or update payment record
            $booking->payment()->updateOrCreate([], [
                'amount' => $booking->total_amount,
                'payment_method' => $validated['payment_method'],
                'status' => $validated['payment_method'] === 'cash'
                    ? PaymentStatus::PendingCash
                    : PaymentStatus::Pending,
            ]);

            // Instant Book skips the request queue, so there is nothing to notify the
            // instructor about or to expire.
            if (! $instantBook) {
                broadcast(new BookingCreated($booking->fresh(['instructor', 'student', 'surfSpot'])))->toOthers();

                // FR 1.9.3 - auto-release the request if the instructor stays silent.
                ExpireBookingAcceptanceJob::dispatch($booking)
                    ->delay($booking->accept_deadline_at ?? now()->addHours(Booking::ACCEPT_WINDOW_HOURS));
            }
        });

        if ($instantBook) {
            return $this->finalizeInstantBook($booking);
        }

        return redirect()->route('bookings.confirmation', $booking->id)
            ->with('success', 'Booking request sent! Your instructor has '
                .Booking::ACCEPT_WINDOW_HOURS.' hours to accept.');
    }

    /**
     * Instant Book: accept on the instructor's behalf, then send the student to
     * whichever step comes next (payment, or straight to confirmation).
     */
    private function finalizeInstantBook(Booking $booking): RedirectResponse
    {
        try {
            $accepted = $this->acceptanceService->accept(
                $booking->fresh(['payment', 'instructor.instructorProfile', 'student', 'surfSpot'])
            );
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('student.bookings.show', $booking->id)
                ->with('error', 'Your request was saved but could not be auto-confirmed. '
                    .'The instructor will respond shortly.');
        }

        if ($accepted->status === BookingStatus::AwaitingPayment) {
            return redirect()->route('student.bookings.show', $accepted->id)
                ->with('success', 'Instant Book confirmed! You have '
                    .Booking::PAYMENT_HOLD_MINUTES.' minutes to complete payment.');
        }

        return redirect()->route('bookings.confirmation', $accepted->id)
            ->with('success', 'Instant Book confirmed! This lesson is locked in.');
    }

    /**
     * Display booking confirmation (Step 6: Confirmation)
     */
    public function confirmation(Booking $booking): Response
    {
        Gate::authorize('view', $booking);

        $booking->load(['instructor', 'surfSpot', 'payment']);

        return Inertia::render('Booking/Confirmation', [
            'booking' => $booking,
            'step' => 6,
        ]);
    }

    /**
     * Display user's bookings
     */
    public function index(Request $request): Response
    {
        $user = auth()->user();
        $filter = $request->query('status', 'all');

        $upcomingStatuses = [
            BookingStatus::Pending,
            BookingStatus::AwaitingPayment,
            BookingStatus::Confirmed,
            BookingStatus::InProgress,
        ];

        $closedStatuses = [
            BookingStatus::Cancelled,
            BookingStatus::Declined,
            BookingStatus::PaymentTimeout,
            BookingStatus::Expired,
            BookingStatus::NoShow,
        ];

        $bookings = Booking::with([
            'instructor',
            'surfSpot',
            'payment',
            'conversation' => function ($query) use ($user) {
                $query->withCount([
                    'messages as unread_count' => fn ($messages) => $messages
                        ->where('sender_id', '!=', $user->id)
                        ->whereNull('read_at'),
                ]);
            },
        ])
            ->where('student_id', $user->id)
            ->when($filter === 'upcoming', fn ($query) => $query->whereIn('status', $upcomingStatuses))
            ->when($filter === 'completed', fn ($query) => $query->where('status', BookingStatus::Completed))
            ->when($filter === 'cancelled', fn ($query) => $query->whereIn('status', $closedStatuses))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $bookings->through(function (Booking $booking) {
            return array_merge($booking->toArray(), [
                'chat_unread_count' => (int) ($booking->conversation?->unread_count ?? 0),
                // Once a GCash receipt has been uploaded (or the payment is
                // already verified) the cancel action is no longer offered.
                'can_be_cancelled' => $booking->canBeCancelled(),
            ]);
        });

        $base = Booking::where('student_id', $user->id);

        return Inertia::render('Student/Bookings/Index', [
            'bookings' => $bookings,
            'filters' => ['status' => $filter],
            'counts' => [
                'all' => (clone $base)->count(),
                'upcoming' => (clone $base)->whereIn('status', $upcomingStatuses)->count(),
                'completed' => (clone $base)->where('status', BookingStatus::Completed)->count(),
                'cancelled' => (clone $base)->whereIn('status', $closedStatuses)->count(),
            ],
        ]);
    }

    /**
     * Display single booking details
     */
    public function show(Booking $booking): Response
    {
        Gate::authorize('view', $booking);

        $booking->load([
            'instructor',
            'surfSpot',
            'payment',
            'review',
            'waiver',
            'participants.waivers',
            // Latest first: the student should see SISA's note on the change
            // that cancelled or refused their lesson.
            'changeRequests' => fn ($query) => $query->latest('id')->limit(5),
        ]);

        // Payment state decides whether cancellation is still allowed.
        $booking->setAttribute('can_be_cancelled', $booking->canBeCancelled());
        $booking->setAttribute('has_submitted_payment', $booking->hasSubmittedPayment());

        $latestChange = $booking->changeRequests->first();

        $booking->setAttribute('latest_change_request', $latestChange ? [
            'id' => $latestChange->id,
            'type' => $latestChange->type?->value,
            'status' => $latestChange->status?->value,
            'reason' => $latestChange->reason,
            'admin_note' => $latestChange->admin_note,
            'reviewed_at' => $latestChange->reviewed_at?->toIso8601String(),
        ] : null);

        return Inertia::render('Student/Bookings/Show', [
            'booking' => $booking,
            'sessionTimeRemaining' => $booking->getSessionTimeRemaining(),
        ]);
    }

    /**
     * Cancel a booking
     */
    public function cancel(Request $request, Booking $booking, AvailabilityService $availabilityService): RedirectResponse
    {
        Gate::authorize('cancel', $booking);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        // FR 1.8.3 - remember the slot before the booking loses its date.
        $date = $availabilityService->dateOf($booking);
        $period = $availabilityService->timePeriodOf($booking);
        $instructorId = $booking->instructor_id;

        $booking->cancel($validated['reason'], CancellationReason::Student);

        // Hand the time slot back so the instructor can be booked again.
        $availabilityService->releaseSlot($instructorId, $date, $period, $booking->id);

        broadcast(new BookingCancelled(
            $booking->fresh(['student', 'instructor', 'surfSpot']),
            $validated['reason']
        ))->toOthers();

        foreach ([$booking->student, $booking->instructor] as $party) {
            $party?->notify(new BookingCancelledNotification($booking, $validated['reason']));
        }

        return back()->with('success', 'Booking cancelled successfully.');
    }
}
