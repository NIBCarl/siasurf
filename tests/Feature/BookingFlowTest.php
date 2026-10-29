<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\InstructorLevel;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\BookingRequestException;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\SurfSpot;
use App\Models\User;
use App\Services\BookingRequestGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The two-phase booking flow with Instant Book:
 *  - deadlines clamped to the lesson start (never open past the lesson)
 *  - the 15-minute payment window (FR 1.4)
 *  - the session-start guard (FR 1.10.1)
 *  - Instant Book skips the acceptance queue
 *  - abuse guards on student requests
 */
class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $instructor;
    private SurfSpot $surfSpot;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        $this->student = User::factory()->create(['role' => 'student']);
        $this->student->assignRole('student');

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 1000,
            'auto_accept_bookings' => false,
        ]);

        $this->surfSpot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
    }

    private function booking(array $attributes = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->surfSpot->id,
            'status' => BookingStatus::Pending,
            'date' => now()->addDays(5)->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
            // The factory randomises 13-50, so a run could silently become a
            // minor and demand parental consent. Pin an adult; the minor case
            // is covered explicitly by its own test.
            'student_age' => 25,
        ], $attributes));
    }

    /**
     * BR-4.4 — payment is gated behind the liability waiver, so every test that
     * reaches the payment step must sign one first.
     */
    private function signLiability(Booking $booking): void
    {
        \App\Models\Waiver::create([
            'booking_id' => $booking->id,
            'type' => 'liability',
            'signed_by' => $this->student->name,
            'signature_data' => 'data:image/png;base64,AAAA',
            'pdf_path' => "waivers/liability_booking_{$booking->id}.pdf",
            'signed_at' => now(),
            'retention_until' => now()->addYears(7),
        ]);
    }

    // ---- Clamped deadlines ---------------------------------------------

    /** @test */
    public function accept_deadline_is_capped_at_the_lesson_start()
    {
        // Lesson is only 2 hours away, so a 2-hour window would run past it.
        $start = now()->addHours(2)->startOfHour();

        $booking = $this->booking([
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
        ]);

        $deadline = $booking->acceptDeadlineFrom();

        // Must land on or before (lesson start - the 1 hour buffer), never after.
        $this->assertTrue(
            $deadline->lessThanOrEqualTo($start->copy()->subHour()),
            'The accept deadline must never run past the lesson start.'
        );
    }

    /** @test */
    public function accept_deadline_is_the_full_window_for_a_distant_lesson()
    {
        $booking = $this->booking(['date' => now()->addDays(5)->format('Y-m-d')]);

        $deadline = $booking->acceptDeadlineFrom();

        $this->assertTrue(
            $deadline->between(now()->addHours(2)->subMinute(), now()->addHours(2)->addMinute()),
            'A lesson far in the future should keep the full 2-hour window.'
        );
    }

    /** @test */
    public function payment_deadline_honours_the_spec_and_is_clamped()
    {
        $this->assertSame(15, Booking::PAYMENT_HOLD_MINUTES);

        // Far-out lesson: full 15 minutes.
        $far = $this->booking(['date' => now()->addDays(3)->format('Y-m-d')]);
        $this->assertTrue(
            $far->paymentDeadlineFrom()->between(now()->addMinutes(14), now()->addMinutes(15)->addMinute())
        );

        // Imminent lesson: clamped before the start.
        $start = now()->addMinutes(40)->startOfMinute();
        $soon = $this->booking([
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
        ]);

        $this->assertTrue($soon->paymentDeadlineFrom()->lessThanOrEqualTo($start));
    }

    /** @test */
    public function a_lesson_too_close_to_start_cannot_be_accepted()
    {
        // Inside the pre-lesson buffer.
        $start = now()->addMinutes(20)->startOfMinute();

        $booking = $this->booking([
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'accept_deadline_at' => now()->addMinutes(10),
        ]);

        $this->assertTrue($booking->isTooCloseToStartToAccept());

        $this->actingAs($this->instructor)
            ->patch(route('instructor.bookings.confirm', $booking->id))
            ->assertRedirect();

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    // ---- Session start guard (FR 1.10.1) -------------------------------

    /** @test */
    public function a_session_cannot_be_started_on_a_future_date()
    {
        $booking = $this->booking([
            'status' => BookingStatus::Confirmed,
            'date' => now()->addDay()->format('Y-m-d'),
            'start_time' => '08:00',
        ]);

        $this->assertFalse($booking->canStartSession());
        $this->assertStringContainsString('not today', $booking->startSessionBlockReason());

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.start-session', $booking->id))
            ->assertRedirect();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertNull($booking->fresh()->started_at);
    }

    /** @test */
    public function a_session_can_be_started_on_the_day_within_tolerance()
    {
        $start = now()->subMinutes(10);

        $booking = $this->booking([
            'status' => BookingStatus::Confirmed,
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
        ]);

        $this->assertTrue($booking->canStartSession(), (string) $booking->startSessionBlockReason());

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.start-session', $booking->id))
            ->assertRedirect();

        $this->assertSame(BookingStatus::InProgress, $booking->fresh()->status);
        $this->assertNotNull($booking->fresh()->started_at);
    }

    /** @test */
    public function a_session_cannot_be_started_far_before_its_scheduled_time()
    {
        // Today, but hours earlier than the booked slot.
        $booking = $this->booking([
            'status' => BookingStatus::Confirmed,
            'date' => now()->format('Y-m-d'),
            'start_time' => now()->addHours(6)->format('H:i'),
        ]);

        $this->assertFalse($booking->canStartSession());

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.start-session', $booking->id))
            ->assertRedirect();

        $this->assertNull($booking->fresh()->started_at);
    }

    /** @test */
    public function a_session_cannot_be_started_after_the_window_closes()
    {
        // Keep this test independent of the wall clock: at 02:00 "six hours
        // ago" belongs to the previous day once the date and time are combined.
        $this->travelTo(now()->setTime(12, 0));

        $booking = $this->booking([
            'status' => BookingStatus::Confirmed,
            'date' => now()->format('Y-m-d'),
            'start_time' => now()->subHours(6)->format('H:i'),
        ]);

        $this->assertFalse($booking->canStartSession());
        $this->assertStringContainsString('window closed', $booking->startSessionBlockReason());
    }

    // ---- Instant Book --------------------------------------------------

    /** @test */
    public function instant_book_confirms_a_cash_booking_without_waiting_for_acceptance()
    {
        $this->instructor->instructorProfile->update(['auto_accept_bookings' => true]);

        $booking = $this->booking(['status' => BookingStatus::Pending]);
        $this->signLiability($booking);

        $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking->id), ['payment_method' => 'cash'])
            ->assertRedirect(route('bookings.confirmation', $booking->id));

        $booking->refresh();

        // Accepted and confirmed in one step - no request queue.
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertNotNull($booking->accepted_at);

        // The slot is locked.
        $this->assertDatabaseHas('availabilities', [
            'user_id' => $this->instructor->id,
            'specific_date' => $booking->date->format('Y-m-d'),
            'time_period' => $booking->time_period->value,
            'is_available' => false,
        ]);
    }

    /** @test */
    public function instant_book_sends_a_gcash_booking_to_the_payment_window()
    {
        $this->instructor->instructorProfile->update(['auto_accept_bookings' => true]);

        $booking = $this->booking(['status' => BookingStatus::Pending]);
        $this->signLiability($booking);

        $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking->id), ['payment_method' => 'gcash'])
            ->assertRedirect(route('student.bookings.show', $booking->id));

        $booking->refresh();

        $this->assertSame(BookingStatus::AwaitingPayment, $booking->status);
        $this->assertNotNull($booking->payment_deadline_at);

        // The 15-minute hold, clamped to the lesson start.
        $this->assertTrue(
            $booking->payment_deadline_at->lessThanOrEqualTo(now()->addMinutes(15)->addMinute())
        );
    }

    /** @test */
    public function a_normal_instructor_still_uses_the_request_queue()
    {
        // auto_accept_bookings stays false.
        $booking = $this->booking(['status' => BookingStatus::Pending]);
        $this->signLiability($booking);

        $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking->id), ['payment_method' => 'cash'])
            ->assertRedirect(route('bookings.confirmation', $booking->id));

        $booking->refresh();

        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertNull($booking->accepted_at);
        $this->assertNotNull($booking->accept_deadline_at);
    }

    // ---- Waiver gate (BR-4.4) ------------------------------------------

    /** @test */
    public function payment_is_blocked_until_the_liability_waiver_is_signed()
    {
        $booking = $this->booking(['status' => BookingStatus::Pending]);

        // No waiver yet - the payment step must bounce back to the waiver page.
        $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking->id), ['payment_method' => 'cash'])
            ->assertRedirect(route('bookings.waiver', $booking->id));

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertNull($booking->fresh()->payment);

        // Sign it, and the same request now goes through.
        $this->signLiability($booking);

        $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking->id), ['payment_method' => 'cash'])
            ->assertRedirect(route('bookings.confirmation', $booking->id));

        $this->assertNotNull($booking->fresh()->payment);
    }

    /** @test */
    public function a_minor_also_needs_parental_consent_before_payment()
    {
        $booking = $this->booking([
            'status' => BookingStatus::Pending,
            'student_age' => 10,
        ]);

        $this->signLiability($booking);

        // Liability alone is not enough for a minor.
        $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking->id), ['payment_method' => 'cash'])
            ->assertRedirect(route('bookings.parental-consent', $booking->id));

        \App\Models\Waiver::create([
            'booking_id' => $booking->id,
            'type' => 'parental_consent',
            'signed_by' => 'Parent Name',
            'signature_data' => 'data:image/png;base64,AAAA',
            'pdf_path' => "waivers/parental_consent_booking_{$booking->id}.pdf",
            'signed_at' => now(),
            'retention_until' => now()->addYears(7),
        ]);

        $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking->id), ['payment_method' => 'cash'])
            ->assertRedirect(route('bookings.confirmation', $booking->id));
    }

    // ---- Abuse guards --------------------------------------------------
    /** @test */
    public function a_student_cannot_file_two_open_requests_for_the_same_slot()
    {
        $slot = [
            'instructor_id' => $this->instructor->id,
            'date' => now()->addDays(4)->format('Y-m-d'),
            'time_period' => 'morning',
        ];

        $this->booking([
            'date' => $slot['date'],
            'time_period' => $slot['time_period'],
            'status' => BookingStatus::Pending,
        ]);

        $guard = app(BookingRequestGuard::class);

        $this->expectException(BookingRequestException::class);

        $guard->assertStudentMayRequest($this->student, $slot);
    }

    /** @test */
    public function a_student_cannot_exceed_the_open_request_limit()
    {
        for ($i = 0; $i < Booking::MAX_OPEN_REQUESTS_PER_STUDENT; $i++) {
            $this->booking([
                'date' => now()->addDays(10 + $i)->format('Y-m-d'),
                'status' => BookingStatus::Pending,
            ]);
        }

        $guard = app(BookingRequestGuard::class);

        $this->expectException(BookingRequestException::class);

        $guard->assertStudentMayRequest($this->student, [
            'instructor_id' => $this->instructor->id,
            'date' => now()->addDays(30)->format('Y-m-d'),
            'time_period' => 'morning',
        ]);
    }

    /** @test */
    public function a_closed_request_does_not_block_a_new_one()
    {
        $this->booking([
            'date' => now()->addDays(4)->format('Y-m-d'),
            'time_period' => 'morning',
            'status' => BookingStatus::Cancelled,
        ]);

        $guard = app(BookingRequestGuard::class);

        $guard->assertStudentMayRequest($this->student, [
            'instructor_id' => $this->instructor->id,
            'date' => now()->addDays(4)->format('Y-m-d'),
            'time_period' => 'morning',
        ]);

        $this->assertTrue(true);
    }

// ---- Cancellation reason -------------------------------------------

    /** @test */
    public function cancellation_records_the_reason_separately_from_the_state()
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);

        $booking->cancel('Flight cancelled.', CancellationReason::Student);

        $booking->refresh();

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(CancellationReason::Student, $booking->cancellation_reason);
        $this->assertFalse($booking->cancellation_reason->isRefundable());

        // The free-text explanation lives in its own column, not in `notes`
        // (which belongs to the student's special requests).
        $this->assertSame('Flight cancelled.', $booking->cancellation_note);
    }

    /** @test */
    public function weather_cancellations_are_refundable_and_instructor_faults_are_not_strikes()
    {
        $this->assertTrue(CancellationReason::Weather->isRefundable());
        $this->assertTrue(CancellationReason::InstructorNoShow->isRefundable());
        $this->assertFalse(CancellationReason::Student->isRefundable());

        // Reliability is tracked separately from the safety strike system.
        $this->assertTrue(CancellationReason::InstructorNoShow->countsAgainstInstructor());
        $this->assertFalse(CancellationReason::Weather->countsAgainstInstructor());
    }

    // ---- Cash settlement (FR 1.4) --------------------------------------

    /** @test */
    public function instructor_can_record_cash_as_received()
    {
        $booking = $this->booking([
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::PendingCash,
        ]);

        Payment::create([
            'booking_id' => $booking->id,
            'amount' => $booking->total_amount,
            'payment_method' => PaymentMethod::Cash,
            'status' => PaymentStatus::PendingCash,
        ]);

        $this->actingAs($this->instructor)
            ->post(route('payments.cash', $booking->id), [
                'amount_received' => $booking->total_amount,
            ])
            ->assertRedirect();

        $booking->refresh();

        $this->assertSame(PaymentStatus::Completed, $booking->payment_status);
        $this->assertSame(PaymentStatus::Completed, $booking->payment->fresh()->status);
        $this->assertNotNull($booking->payment->fresh()->paid_at);

        // Recording cash must not alter the booking lifecycle state.
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
    }

    /** @test */
    public function cash_cannot_be_recorded_on_a_cancelled_booking()
    {
        $booking = $this->booking([
            'status' => BookingStatus::Cancelled,
            'payment_status' => PaymentStatus::PendingCash,
        ]);

        $this->assertFalse($booking->canRecordCashPayment());

        $this->actingAs($this->instructor)
            ->post(route('payments.cash', $booking->id), [
                'amount_received' => $booking->total_amount,
            ])
            ->assertRedirect();

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }

    /** @test */
    public function instructor_can_toggle_instant_book_from_the_availability_page()
    {
        $this->assertFalse($this->instructor->instructorProfile->fresh()->auto_accept_bookings);

        $this->actingAs($this->instructor)
            ->post(route('instructor.availability.toggle-instant-book'))
            ->assertRedirect();

        $this->assertTrue($this->instructor->instructorProfile->fresh()->auto_accept_bookings);

        // Toggling back off returns to the review queue.
        $this->actingAs($this->instructor)
            ->post(route('instructor.availability.toggle-instant-book'))
            ->assertRedirect();

        $this->assertFalse($this->instructor->instructorProfile->fresh()->auto_accept_bookings);
    }

    /** @test */
    public function the_availability_page_reports_the_instant_book_state()
    {
        $this->instructor->instructorProfile->update(['auto_accept_bookings' => true]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.availability.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Instructor/Availability/Index')
                ->where('autoAcceptBookings', true)
            );
    }
}
