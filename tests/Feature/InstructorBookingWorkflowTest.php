<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\InstructorLevel;
use App\Jobs\ExpireBookingAcceptanceJob;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers the instructor-side requirements: FR 1.9.3 (accept/decline within 2
 * hours), FR 1.9.4 (cancel or reschedule with admin approval under 24 hours),
 * and FR 1.10.1 (attendance marking).
 */
class InstructorBookingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $instructor;
    private User $admin;
    private SurfSpot $surfSpot;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');

        $this->student = User::factory()->create(['role' => 'student']);
        $this->student->assignRole('student');

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 1000,
        ]);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('admin');

        $this->surfSpot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
    }

    /**
     * A booking the instructor has not answered yet.
     */
    private function pendingBooking(array $attributes = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->surfSpot->id,
            'status' => BookingStatus::Pending,
            'date' => now()->addDays(3)->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'accept_deadline_at' => now()->addHours(2),
        ], $attributes));
    }

    /**
     * A confirmed lesson that is still open to changes.
     */
    private function confirmedBooking(array $attributes = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->surfSpot->id,
            'status' => BookingStatus::Confirmed,
            'date' => now()->addDays(3)->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
        ], $attributes));
    }

    // ---- FR 1.9.3 accept / decline -------------------------------------

    /** @test */
    public function acceptance_window_is_two_hours()
    {
        $this->assertSame(2, Booking::ACCEPT_WINDOW_HOURS);

        $booking = $this->pendingBooking(['accept_deadline_at' => null]);

        $this->assertNull($booking->accept_deadline_at);

        $deadline = $booking->acceptDeadlineFrom();

        $this->assertTrue(
            $deadline->between(now()->addHours(2)->subMinute(), now()->addHours(2)->addMinute()),
            'The acceptance window should close two hours after the request lands.'
        );
    }

    /** @test */
    public function instructor_can_decline_a_request_inside_the_window()
    {
        $booking = $this->pendingBooking();

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.decline', $booking->id), [
                'reason' => 'Already booked that morning.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => BookingStatus::Declined->value,
            'decline_reason' => 'Already booked that morning.',
        ]);
    }

    /** @test */
    public function instructor_cannot_decline_once_the_window_has_closed()
    {
        $booking = $this->pendingBooking(['accept_deadline_at' => now()->subMinute()]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.decline', $booking->id), ['reason' => 'Too slow.'])
            ->assertRedirect();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => BookingStatus::Pending->value,
        ]);
    }

    /** @test */
    public function instructor_cannot_decline_another_instructors_request()
    {
        $otherInstructor = User::factory()->create(['role' => 'instructor']);
        $otherInstructor->assignRole('instructor');
        $otherInstructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 1000,
        ]);

        $booking = $this->pendingBooking(['instructor_id' => $otherInstructor->id]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.decline', $booking->id), ['reason' => 'Nope.'])
            ->assertForbidden();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => BookingStatus::Pending->value,
        ]);
    }

    /** @test */
    public function unanswered_request_expires_and_returns_the_slot()
    {
        $booking = $this->pendingBooking(['accept_deadline_at' => now()->subMinute()]);

        app(AvailabilityService::class)->blockForBooking($booking);

        $this->assertDatabaseHas('availabilities', [
            'user_id' => $this->instructor->id,
            'specific_date' => $booking->date->format('Y-m-d'),
            'time_period' => $booking->time_period->value,
            'is_available' => false,
        ]);

        (new ExpireBookingAcceptanceJob($booking))->handle(app(AvailabilityService::class));

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => BookingStatus::Expired->value,
        ]);

        $this->assertDatabaseMissing('availabilities', [
            'user_id' => $this->instructor->id,
            'specific_date' => $booking->date->format('Y-m-d'),
            'time_period' => $booking->time_period->value,
            'is_available' => false,
        ]);
    }

    /** @test */
    public function stale_request_sweep_queues_expiry_jobs()
    {
        Queue::fake();

        $this->pendingBooking(['accept_deadline_at' => now()->subMinutes(5)]);

        $this->artisan('bookings:expire-stale-requests')->assertSuccessful();

        Queue::assertPushed(ExpireBookingAcceptanceJob::class);
    }

    // ---- FR 1.9.4 cancel / reschedule ----------------------------------

    /** @test */
    public function reschedule_more_than_24_hours_ahead_applies_immediately()
    {
        $booking = $this->confirmedBooking(['date' => now()->addDays(4)->format('Y-m-d')]);

        $newDate = now()->addDays(6)->format('Y-m-d');

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $booking->id), [
                'type' => 'reschedule',
                'date' => $newDate,
                'time_period' => 'afternoon',
                'start_time' => '13:00',
                'end_time' => '15:00',
                'reason' => 'Better tide window.',
            ])
            ->assertRedirect();

        $booking->refresh();

        $this->assertSame($newDate, $booking->date->format('Y-m-d'));
        $this->assertSame('afternoon', $booking->time_period->value);

        $this->assertDatabaseHas('booking_change_requests', [
            'booking_id' => $booking->id,
            'type' => 'reschedule',
            'status' => ChangeRequestStatus::AutoApproved->value,
            'requires_admin_approval' => false,
        ]);
    }

    /** @test */
    public function reschedule_within_24_hours_waits_for_admin_approval()
    {
        $start = now()->addHours(3);

        $booking = $this->confirmedBooking([
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
        ]);

        $this->assertTrue($booking->changeRequiresAdminApproval());

        $originalDate = $booking->date->format('Y-m-d');

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $booking->id), [
                'type' => 'reschedule',
                'date' => now()->addDays(5)->format('Y-m-d'),
                'time_period' => 'morning',
                'start_time' => '07:00',
                'end_time' => '09:00',
                'reason' => 'Family emergency.',
            ])
            ->assertRedirect();

        // The booking keeps its original slot until SISA signs off.
        $this->assertSame($originalDate, $booking->fresh()->date->format('Y-m-d'));

        $this->assertDatabaseHas('booking_change_requests', [
            'booking_id' => $booking->id,
            'status' => ChangeRequestStatus::Pending->value,
            'requires_admin_approval' => true,
        ]);
    }

    /** @test */
    public function changes_are_blocked_while_one_is_already_pending()
    {
        $start = now()->addHours(3);

        $booking = $this->confirmedBooking([
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
        ]);

        $payload = [
            'type' => 'cancellation',
            'reason' => 'Cannot make it.',
        ];

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $booking->id), $payload)
            ->assertRedirect();

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $booking->id), $payload)
            ->assertRedirect();

        $this->assertSame(1, $booking->changeRequests()->count());
    }

    /** @test */
    public function instructor_can_withdraw_a_pending_change_request()
    {
        $start = now()->addHours(3);

        $booking = $this->confirmedBooking([
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $booking->id), [
                'type' => 'cancellation',
                'reason' => 'Changed my mind.',
            ]);

        $this->assertSame(1, $booking->changeRequests()->count());

        $this->actingAs($this->instructor)
            ->delete(route('instructor.bookings.change-request.cancel', $booking->id))
            ->assertRedirect();

        $this->assertSame(0, $booking->changeRequests()->count());
    }

    /** @test */
    public function admin_can_approve_a_late_reschedule()
    {
        $start = now()->addHours(3);

        $booking = $this->confirmedBooking([
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
        ]);

        $newDate = now()->addDays(5)->format('Y-m-d');

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $booking->id), [
                'type' => 'reschedule',
                'date' => $newDate,
                'time_period' => 'morning',
                'start_time' => '07:00',
                'end_time' => '09:00',
                'reason' => 'Tide conditions.',
            ]);

        $changeRequest = $booking->changeRequests()->latest()->first();

        $this->actingAs($this->admin)
            ->post(route('admin.change-requests.approve', $changeRequest->id), [
                'admin_note' => 'Approved, the tide window justifies the move.',
            ])
            ->assertRedirect();

        $this->assertSame($newDate, $booking->fresh()->date->format('Y-m-d'));

        $this->assertDatabaseHas('booking_change_requests', [
            'id' => $changeRequest->id,
            'status' => ChangeRequestStatus::Approved->value,
            'reviewed_by' => $this->admin->id,
        ]);
    }

    /** @test */
    public function admin_can_reject_a_late_change_and_the_slot_is_kept()
    {
        $start = now()->addHours(3);

        $booking = $this->confirmedBooking([
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
        ]);

        $originalDate = $booking->date->format('Y-m-d');

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $booking->id), [
                'type' => 'cancellation',
                'reason' => 'Personal reasons.',
            ]);

        $changeRequest = $booking->changeRequests()->latest()->first();

        $this->actingAs($this->admin)
            ->post(route('admin.change-requests.reject', $changeRequest->id), [
                'admin_note' => 'Too close to the lesson start.',
            ])
            ->assertRedirect();

        $this->assertSame($originalDate, $booking->fresh()->date->format('Y-m-d'));
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        $this->assertDatabaseHas('booking_change_requests', [
            'id' => $changeRequest->id,
            'status' => ChangeRequestStatus::Rejected->value,
        ]);
    }

    /** @test */
    public function only_admins_can_review_change_requests()
    {
        $start = now()->addHours(3);

        $booking = $this->confirmedBooking([
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $booking->id), [
                'type' => 'cancellation',
                'reason' => 'Personal reasons.',
            ]);

        $changeRequest = $booking->changeRequests()->latest()->first();

        $this->actingAs($this->instructor)
            ->post(route('admin.change-requests.approve', $changeRequest->id))
            ->assertForbidden();

        $this->assertDatabaseHas('booking_change_requests', [
            'id' => $changeRequest->id,
            'status' => ChangeRequestStatus::Pending->value,
        ]);
    }

    // ---- FR 1.10.1 session tracking ------------------------------------

    /** @test */
    public function instructor_can_mark_a_student_present()
    {
        $booking = $this->confirmedBooking();

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.attendance', $booking->id), [
                'attendance_status' => AttendanceStatus::Present->value,
            ])
            ->assertRedirect();

        $booking->refresh();

        $this->assertSame(AttendanceStatus::Present, $booking->attendance_status);
        $this->assertSame($this->instructor->id, $booking->attendance_marked_by);
        $this->assertNotNull($booking->attendance_marked_at);
        $this->assertTrue($booking->studentAttended());
    }

    /** @test */
    public function marking_absent_on_a_live_session_closes_it()
    {
        $booking = $this->confirmedBooking([
            'status' => BookingStatus::InProgress,
            'started_at' => now()->subMinutes(30),
            'duration_hours' => 2,
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.attendance', $booking->id), [
                'attendance_status' => AttendanceStatus::Absent->value,
            ])
            ->assertRedirect();

        $booking->refresh();

        $this->assertSame(AttendanceStatus::Absent, $booking->attendance_status);
        $this->assertSame(BookingStatus::Completed, $booking->status);
        $this->assertFalse($booking->studentAttended());
    }

    /** @test */
    public function attendance_cannot_be_marked_on_a_pending_request()
    {
        $booking = $this->pendingBooking();

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.attendance', $booking->id), [
                'attendance_status' => AttendanceStatus::Present->value,
            ])
            ->assertRedirect();

        $this->assertNull($booking->fresh()->attendance_status);
    }

    /** @test */
    public function attendance_rejects_an_unknown_status()
    {
        $booking = $this->confirmedBooking();

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.attendance', $booking->id), [
                'attendance_status' => 'maybe',
            ])
            ->assertSessionHasErrors('attendance_status');

        $this->assertNull($booking->fresh()->attendance_status);
    }

    /** @test */
    public function instructor_cannot_mark_attendance_for_another_instructor()
    {
        $otherInstructor = User::factory()->create(['role' => 'instructor']);
        $otherInstructor->assignRole('instructor');
        $otherInstructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 1000,
        ]);

        $booking = $this->confirmedBooking(['instructor_id' => $otherInstructor->id]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.attendance', $booking->id), [
                'attendance_status' => AttendanceStatus::Present->value,
            ])
            ->assertForbidden();

        $this->assertNull($booking->fresh()->attendance_status);
    }

    /** @test */
    public function student_cancellation_returns_the_time_slot()
    {
        $booking = $this->confirmedBooking();

        app(AvailabilityService::class)->blockForBooking($booking);

        $this->assertDatabaseHas('availabilities', [
            'user_id' => $this->instructor->id,
            'specific_date' => $booking->date->format('Y-m-d'),
            'time_period' => $booking->time_period->value,
            'is_available' => false,
        ]);

        $this->actingAs($this->student)
            ->post(route('student.bookings.cancel', $booking->id), [
                'reason' => 'Flight was cancelled.',
            ])
            ->assertRedirect();

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);

        $this->assertDatabaseMissing('availabilities', [
            'user_id' => $this->instructor->id,
            'specific_date' => $booking->date->format('Y-m-d'),
            'time_period' => $booking->time_period->value,
            'is_available' => false,
        ]);
    }
}