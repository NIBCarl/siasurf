<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    /**
     * Display a listing of bookings for the instructor.
     */
    public function index(): Response
    {
        $user = auth()->user();

        $bookings = Booking::with(['student', 'surfSpot'])
            ->where('instructor_id', $user->id)
            ->latest()
            ->paginate(10);

        return Inertia::render('Instructor/Bookings/Index', [
            'bookings' => $bookings,
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

        $booking->load(['student.studentProfile', 'student.skillUpgradeRequests', 'surfSpot', 'payment', 'waiver', 'review']);

        return Inertia::render('Instructor/Bookings/Show', [
            'booking' => $booking,
        ]);
    }

    /**
     * Confirm a booking.
     */
    public function confirm(Booking $booking)
    {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        $payment = $booking->payment;
        $paymentMethod = $payment ? $payment->payment_method->value : 'cash';

        if ($paymentMethod === 'gcash') {
            \Illuminate\Support\Facades\DB::transaction(function () use ($booking, $payment) {
                // Set status to AwaitingPayment and payment_deadline_at to 30 minutes from now
                $booking->update([
                    'status' => \App\Enums\BookingStatus::AwaitingPayment,
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
            \Illuminate\Support\Facades\DB::transaction(function () use ($booking) {
                // Cash payment method -> Confirm directly
                $booking->update(['status' => \App\Enums\BookingStatus::Confirmed]);

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

    /**
     * Start a surfing session.
     */
    public function startSession(Booking $booking)
    {
        if ($booking->instructor_id !== auth()->id()) {
            abort(403);
        }

        // Only confirmed bookings can start
        if ($booking->status !== \App\Enums\BookingStatus::Confirmed) {
            return back()->with('error', 'Only confirmed bookings can be started.');
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
}
