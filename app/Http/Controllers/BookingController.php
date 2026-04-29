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

    public function __construct(
        SafetyService $safetyService,
        PricingService $pricingService
    ) {
        $this->safetyService = $safetyService;
        $this->pricingService = $pricingService;
    }

    /**
     * Display the booking creation form (Step 1: Select instructor)
     */
    public function create(Request $request, User $instructor): Response
    {
        Gate::authorize('create', Booking::class);

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
            'step' => 1,
        ]);
    }

    /**
     * Store booking details (Step 2: Enter details)
     */
    public function storeDetails(Request $request, User $instructor): Response|RedirectResponse
    {
        Gate::authorize('create', Booking::class);

        $validated = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i|after:' . now()->subHour()->format('H:i'),
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

        return redirect()->route('bookings.waiver', $booking->id)
            ->with('success', 'Details saved. Please sign the waiver.');
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

        $booking->confirm();

        return back()->with('success', 'Booking confirmed successfully.');
    }

    /**
     * Create booking record
     */
    private function createBooking(array $data, string $paymentMethod): Booking
    {
        return DB::transaction(function () use ($data, $paymentMethod) {
            $booking = Booking::create([
                'student_id' => auth()->id(),
                'instructor_id' => $data['instructor_id'],
                'surf_spot_id' => $data['surf_spot_id'],
                'date' => $data['date'],
                'time_period' => $data['time_period'],
                'skill_level' => $data['skill_level'],
                'student_age' => $data['student_age'],
                'student_count' => $data['student_count'],
                'status' => BookingStatus::Pending,
                'total_amount' => $data['pricing']['total'],
                'payment_status' => $paymentMethod === 'cash' 
                    ? PaymentStatus::PendingCash 
                    : PaymentStatus::Pending,
                'notes' => $data['notes'] ?? null,
            ]);

            // Create payment record
            $booking->payment()->create([
                'amount' => $data['pricing']['total'],
                'payment_method' => $paymentMethod,
                'status' => $paymentMethod === 'cash' 
                    ? PaymentStatus::PendingCash 
                    : PaymentStatus::Pending,
            ]);

            // Broadcast booking created event
            broadcast(new BookingCreated($booking->fresh(['instructor', 'student', 'surfSpot'])))->toOthers();

            return $booking;
        });
    }
}