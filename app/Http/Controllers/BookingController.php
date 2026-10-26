<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\User;
use App\Models\SurfSpot;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\SkillLevel;
use App\Enums\TimePeriod;
use App\Services\SafetyService;
use App\Services\PricingService;
use App\Services\StormglassService;
use App\Services\BookingAcceptanceService;
use App\Services\BookingRequestGuard;
use App\Exceptions\BookingRequestException;
use App\Events\BookingCreated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class BookingController extends Controller
{
    protected SafetyService $safetyService;
    protected PricingService $pricingService;
    protected StormglassService $stormglassService;
    protected BookingRequestGuard $requestGuard;
    protected BookingAcceptanceService $acceptanceService;
    protected \App\Services\AvailabilityService $availabilityService;

    public function __construct(
        SafetyService $safetyService,
        PricingService $pricingService,
        StormglassService $stormglassService,
        BookingRequestGuard $requestGuard,
        BookingAcceptanceService $acceptanceService,
        \App\Services\AvailabilityService $availabilityService
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

        if (!$instructor->isBookable()) {
            return redirect()->route('instructors.search')->with('error', 'This instructor is currently not available for bookings.');
        }

        $instructor->load(['instructorProfile', 'availabilities']);

        // Get safety rules applicable to this instructor
        $safetyRules = $this->safetyService->getApplicableRules(
            $instructor->instructorProfile->level->value
        );

        // Get available surf spots based on skill level
        $surfSpots = SurfSpot::where('is_active', true)
            ->orderBy('difficulty')
            ->get();

        return Inertia::render('Booking/Create', [
            'instructor' => $instructor,
            'surfSpots' => $surfSpots,
            'safetyRules' => $safetyRules,
            'tideData' => $this->stormglassService->getUpcomingTides(7),
            'step' => 1,
        ]);
    }

    /**
     * Store booking details (Step 2: Enter details)
     */
    public function storeDetails(Request $request, User $instructor): Response|RedirectResponse
    {
        Gate::authorize('create', Booking::class);

        if (!$instructor->isBookable()) {
            return redirect()->route('instructors.search')->with('error', 'This instructor is currently not available for bookings.');
        }

        $validated = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'start_time' => [
                'required',
                'date_format:H:i',
                function ($attribute, $value, $fail) use ($request) {
                    $dateTime = \Carbon\Carbon::parse($request->date . ' ' . $value);
                    if ($dateTime->isPast()) {
                        $fail('The ' . $attribute . ' must be in the future.');
                    }
                }
            ],
            'skill_level' => 'required|in:beginner,intermediate,advanced',
            'student_age' => 'required|integer|min:5|max:100',
            'height' => 'required|numeric|min:50|max:250',
            'weight' => 'required|numeric|min:10|max:200',
            'student_count' => 'required|integer|min:1|max:10',
            'surf_spot_id' => 'required|exists:surf_spots,id',
            'has_board' => 'required|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Calculate end time (1 hour sessions)
        $startTime = \Carbon\Carbon::parse($validated['start_time']);
        $endTime = $startTime->copy()->addHour();
        $validated['end_time'] = $endTime->format('H:i');
        $validated['duration_hours'] = 1;

        // Determine time period based on start time
        $validated['time_period'] = $startTime->hour < 12 ? 'morning' : 'afternoon';

        // Get surf spot for validation
        $surfSpot = SurfSpot::findOrFail($validated['surf_spot_id']);

        // Check tide conditions for the selected date/time
        $tideWarning = $this->stormglassService->getTideWarning(
            $validated['date'],
            $validated['start_time']
        );

        // Step 3: Safety validation using SafetyService
        try {
            $this->safetyService->validateBookingSafety([
                'instructor_level' => $instructor->instructorProfile->level->value,
                'student_count' => $validated['student_count'],
                'skill_level' => $validated['skill_level'],
                'student_age' => $validated['student_age'],
                'surf_spot_difficulty' => $surfSpot->difficulty,
            ], auth()->id());
        } catch (\App\Exceptions\SafetyViolationException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Check for time slot conflicts (double-booking prevention)
        if ($this->safetyService->hasTimeConflict(
            $instructor->id,
            $validated['date'],
            $validated['start_time'],
            $validated['end_time']
        )) {
            return back()->with('error', 'This time slot is no longer available. Please choose another time.');
        }

        // FR 1.8 - the instructor's own availability must be respected. Without
        // this the weekly schedule and personal blocks had no effect on booking.
        try {
            $this->availabilityService->assertSlotIsBookable(
                $instructor->id,
                $validated['date'],
                $validated['time_period'],
                $validated['start_time']
            );
        } catch (BookingRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        // Calculate pricing using PricingService
        $pricing = $this->pricingService->calculatePrice(
            $instructor->instructorProfile,
            $validated['student_count']
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

        // Create initial booking record in database
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
    public function payment(Booking $booking): Response
    {
        Gate::authorize('view', $booking);

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
        Gate::authorize('update', $booking);

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
            if (!$instantBook) {
                broadcast(new BookingCreated($booking->fresh(['instructor', 'student', 'surfSpot'])))->toOthers();

                // FR 1.9.3 - auto-release the request if the instructor stays silent.
                \App\Jobs\ExpireBookingAcceptanceJob::dispatch($booking)
                    ->delay($booking->accept_deadline_at ?? now()->addHours(Booking::ACCEPT_WINDOW_HOURS));
            }
        });

        if ($instantBook) {
            return $this->finalizeInstantBook($booking);
        }

        return redirect()->route('bookings.confirmation', $booking->id)
            ->with('success', 'Booking request sent! Your instructor has '
                . Booking::ACCEPT_WINDOW_HOURS . ' hours to accept.');
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
                    . 'The instructor will respond shortly.');
        }

        if ($accepted->status === BookingStatus::AwaitingPayment) {
            return redirect()->route('student.bookings.show', $accepted->id)
                ->with('success', 'Instant Book confirmed! You have '
                    . Booking::PAYMENT_HOLD_MINUTES . ' minutes to complete payment.');
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
    public function index(): Response
    {
        $user = auth()->user();

        $bookings = Booking::with(['instructor', 'surfSpot'])
            ->where('student_id', $user->id)
            ->latest()
            ->paginate(10);

        return Inertia::render('Student/Bookings/Index', [
            'bookings' => $bookings,
        ]);
    }

    /**
     * Display single booking details
     */
    public function show(Booking $booking): Response
    {
        Gate::authorize('view', $booking);

        $booking->load(['instructor', 'surfSpot', 'payment', 'review', 'waiver']);

        return Inertia::render('Student/Bookings/Show', [
            'booking' => $booking,
            'sessionTimeRemaining' => $booking->getSessionTimeRemaining(),
        ]);
    }

    /**
     * Cancel a booking
     */
    public function cancel(Request $request, Booking $booking, \App\Services\AvailabilityService $availabilityService): RedirectResponse
    {
        Gate::authorize('cancel', $booking);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        // FR 1.8.3 - remember the slot before the booking loses its date.
        $date = $availabilityService->dateOf($booking);
        $period = $availabilityService->timePeriodOf($booking);
        $instructorId = $booking->instructor_id;

        $booking->cancel($validated['reason'], \App\Enums\CancellationReason::Student);

        // Hand the time slot back so the instructor can be booked again.
        $availabilityService->releaseSlot($instructorId, $date, $period, $booking->id);

        broadcast(new \App\Events\BookingCancelled(
            $booking->fresh(['student', 'instructor', 'surfSpot']),
            $validated['reason']
        ))->toOthers();

        foreach ([$booking->student, $booking->instructor] as $party) {
            $party?->notify(new \App\Notifications\BookingCancelledNotification($booking, $validated['reason']));
        }

        return back()->with('success', 'Booking cancelled successfully.');
    }

}
