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

    public function __construct(
        SafetyService $safetyService,
        PricingService $pricingService,
        StormglassService $stormglassService
    ) {
        $this->safetyService = $safetyService;
        $this->pricingService = $pricingService;
        $this->stormglassService = $stormglassService;
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

        // Calculate pricing using PricingService
        $pricing = $this->pricingService->calculatePrice(
            $instructor->instructorProfile,
            $validated['student_count']
        );

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

        return DB::transaction(function () use ($booking, $validated) {
            // Update booking status
            $booking->update([
                'payment_status' => $validated['payment_method'] === 'cash' 
                    ? PaymentStatus::PendingCash 
                    : PaymentStatus::Pending,
                'status' => BookingStatus::Pending, // Status remains pending until confirmed or paid
            ]);

            // Create or update payment record
            $booking->payment()->updateOrCreate([], [
                'amount' => $booking->total_amount,
                'payment_method' => $validated['payment_method'],
                'status' => $validated['payment_method'] === 'cash' 
                    ? PaymentStatus::PendingCash 
                    : PaymentStatus::Pending,
            ]);

            // Broadcast booking created event (since it's now finalized for the student)
            broadcast(new BookingCreated($booking->fresh(['instructor', 'student', 'surfSpot'])))->toOthers();

            return redirect()->route('bookings.confirmation', $booking->id)
                ->with('success', 'Booking finalized! Please wait for confirmation.');
        });
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
    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        Gate::authorize('cancel', $booking);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $booking->cancel($validated['reason']);

        return back()->with('success', 'Booking cancelled successfully.');
    }

    /**
     * Confirm a booking (Instructor only)
     */
    public function confirm(Booking $booking): RedirectResponse
    {
        Gate::authorize('confirm', $booking);

        // Re-check for time slot conflicts before confirming
        if ($this->safetyService->hasTimeConflict(
            $booking->instructor_id,
            $booking->date instanceof \DateTimeInterface ? $booking->date->format('Y-m-d') : $booking->date,
            $booking->start_time,
            $booking->end_time,
            $booking->id
        )) {
            return back()->with('error', 'This time slot has been taken by another booking. Cannot confirm.');
        }

        $payment = $booking->payment;
        $paymentMethod = $payment ? $payment->payment_method->value : 'cash';

        if ($paymentMethod === 'gcash') {
            DB::transaction(function () use ($booking, $payment) {
                // Set status to AwaitingPayment and payment_deadline_at to 30 minutes from now
                $booking->update([
                    'status' => BookingStatus::AwaitingPayment,
                    'payment_deadline_at' => now()->addMinutes(30),
                ]);

                $bookingDate = $booking->date instanceof \DateTimeInterface ? $booking->date : new \DateTime($booking->date);
                $dayOfWeek = (int) $bookingDate->format('w');

                // Block instructor availability for that time slot
                \App\Models\Availability::updateOrCreate(
                    [
                        'user_id' => $booking->instructor_id,
                        'specific_date' => $booking->date instanceof \DateTimeInterface ? $booking->date->format('Y-m-d') : $booking->date,
                        'time_period' => $booking->time_period instanceof \BackedEnum ? $booking->time_period->value : $booking->time_period,
                    ],
                    [
                        'day_of_week' => $dayOfWeek,
                        'is_available' => false,
                    ]
                );

                // Create GCash payment intent using the active payment service
                $paymentService = app(\App\Contracts\PaymentServiceInterface::class);
                $paymentIntent = $paymentService->createPaymentIntent($booking);

                // Update the payment record with the transaction ID and save checkout URL to notes
                if ($payment) {
                    $payment->update([
                        'transaction_id' => $paymentIntent['transaction_id'],
                        'notes' => json_encode(['checkout_url' => $paymentIntent['checkout_url']]),
                    ]);
                }

                // Broadcast Awaiting Payment WebSocket event
                broadcast(new \App\Events\BookingAwaitingPayment($booking->fresh(['instructor', 'student', 'surfSpot'])))->toOthers();

                // Dispatch ExpirePaymentWindowJob to run in 30 minutes
                \App\Jobs\ExpirePaymentWindowJob::dispatch($booking)->delay(now()->addMinutes(30));
            });

            return back()->with('success', 'Booking accepted. Awaiting payment from the student.');
        } else {
            DB::transaction(function () use ($booking) {
                // Cash payment method -> Confirm directly
                $booking->update(['status' => BookingStatus::Confirmed]);

                $bookingDate = $booking->date instanceof \DateTimeInterface ? $booking->date : new \DateTime($booking->date);
                $dayOfWeek = (int) $bookingDate->format('w');

                // Block instructor availability for that time slot
                \App\Models\Availability::updateOrCreate(
                    [
                        'user_id' => $booking->instructor_id,
                        'specific_date' => $booking->date instanceof \DateTimeInterface ? $booking->date->format('Y-m-d') : $booking->date,
                        'time_period' => $booking->time_period instanceof \BackedEnum ? $booking->time_period->value : $booking->time_period,
                    ],
                    [
                        'day_of_week' => $dayOfWeek,
                        'is_available' => false,
                    ]
                );

                // Broadcast booking confirmed event
                broadcast(new \App\Events\BookingConfirmed($booking->fresh(['instructor', 'student', 'surfSpot'])))->toOthers();
            });

            return back()->with('success', 'Booking confirmed successfully.');
        }
    }
}