<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\InstructorLevel;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
use App\Jobs\ExpireBookingAcceptanceJob;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use App\Notifications\BookingSpotChangedNotification;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
            'skill_level' => SkillLevel::Beginner->value,
            'session_type' => SessionType::Teaching->value,
            'date' => now()->addDays(3)->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
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
            'skill_level' => SkillLevel::Beginner->value,
            'session_type' => SessionType::Teaching->value,
            'date' => now()->addDays(3)->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
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
    public function reschedule_more_than_24_hours_ahead_notifies_student_posts_chat_and_applies_after_agreement()
    {
        $originalDate = now()->addDays(4)->format('Y-m-d');
        $booking = $this->confirmedBooking(['date' => $originalDate]);

        $newDate = now()->addDays(6)->format('Y-m-d');

        // Instructor only supplies date, start_time, and reason; period & end_time are automated.
        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $booking->id), [
                'type' => 'reschedule',
                'date' => $newDate,
                'start_time' => '13:00',
                'reason' => 'Better tide window.',
            ])
            ->assertRedirect();

        $booking->refresh();

        // Schedule must NOT move before the student agrees.
        $this->assertSame($originalDate, $booking->date->format('Y-m-d'));

        $this->assertDatabaseHas('booking_change_requests', [
            'booking_id' => $booking->id,
            'type' => 'reschedule',
            'status' => ChangeRequestStatus::AwaitingStudent->value,
            'requires_admin_approval' => false,
            'new_time_period' => 'afternoon',
        ]);

        // Reason was automatically posted to the booking Messages thread.
        $this->assertDatabaseHas('messages', [
            'sender_id' => $this->instructor->id,
        ]);
        $this->assertStringContainsString(
            'Better tide window.',
            \App\Models\Message::latest('id')->first()->body
        );

        // Student agrees -> booking moves and end_time is auto-calculated (15:00).
        $this->actingAs($this->student)
            ->post(route('student.bookings.accept-reschedule', $booking->id))
            ->assertRedirect();

        $booking->refresh();
        $this->assertSame($newDate, $booking->date->format('Y-m-d'));
        $this->assertSame('afternoon', $booking->time_period->value);
        $this->assertSame('13:00', $booking->start_time->format('H:i'));
        $this->assertSame('15:00', $booking->end_time->format('H:i'));

        $this->assertDatabaseHas('booking_change_requests', [
            'booking_id' => $booking->id,
            'type' => 'reschedule',
            'status' => ChangeRequestStatus::AutoApproved->value,
        ]);
    }

    /** @test */
    public function reschedule_within_24_hours_waits_for_student_agreement_then_admin_approval()
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
                'start_time' => '07:00',
                'reason' => 'Family emergency.',
            ])
            ->assertRedirect();

        // Starts in awaiting_student; booking keeps original slot.
        $this->assertSame($originalDate, $booking->fresh()->date->format('Y-m-d'));
        $this->assertDatabaseHas('booking_change_requests', [
            'booking_id' => $booking->id,
            'status' => ChangeRequestStatus::AwaitingStudent->value,
            'requires_admin_approval' => true,
        ]);

        // Student agrees -> transitions to Pending for SISA approval.
        $this->actingAs($this->student)
            ->post(route('student.bookings.accept-reschedule', $booking->id))
            ->assertRedirect();

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
    public function admin_can_approve_a_late_reschedule_after_student_agrees()
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
                'start_time' => '07:00',
                'reason' => 'Tide conditions.',
            ]);

        $this->actingAs($this->student)
            ->post(route('student.bookings.accept-reschedule', $booking->id));

        $changeRequest = $booking->changeRequests()->latest('id')->first();

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
    public function instructor_can_mark_a_student_present_once_scheduled_time_starts()
    {
        $start = now()->subMinutes(15);
        $booking = $this->confirmedBooking([
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
        ]);

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
    public function marking_absent_on_a_live_session_marks_no_show()
    {
        $start = now()->subMinutes(30);
        $booking = $this->confirmedBooking([
            'status' => BookingStatus::InProgress,
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
            'started_at' => $start,
            'duration_hours' => 2,
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.attendance', $booking->id), [
                'attendance_status' => AttendanceStatus::Absent->value,
            ])
            ->assertRedirect();

        $booking->refresh();

        $this->assertSame(AttendanceStatus::Absent, $booking->attendance_status);
        $this->assertSame(BookingStatus::NoShow, $booking->status);
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
        $start = now()->subMinutes(15);
        $booking = $this->confirmedBooking([
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
        ]);

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

    /** @test */
    public function instructor_can_change_surf_spot_for_shared_session_and_notifies_all_students_while_enforcing_safety_rule_5(): void
    {
        Notification::fake();

        $l3 = User::factory()->create(['role' => 'instructor']);
        $l3->assignRole('instructor');
        $l3->instructorProfile()->create([
            'level' => InstructorLevel::Level3,
            'offered_services' => ['teaching', 'guiding', 'coaching'],
            'status' => 'active',
            'rate_per_hour' => 700,
            'guiding_rate' => 1000,
            'coaching_rate' => 1600,
        ]);

        $spotA = $this->surfSpot;
        $spotB = SurfSpot::factory()->create(['difficulty' => 'beginner', 'name' => 'Burgos (Inner)']);
        $advSpot = SurfSpot::factory()->create(['difficulty' => 'advanced', 'name' => 'Cloud 9 Outer']);
        $l3->instructorProfile->surfSpots()->sync([$spotA->id, $spotB->id, $advSpot->id]);

        $studentA = $this->student;
        $studentB = User::factory()->create(['role' => 'student']);
        $studentB->assignRole('student');
        $studentC = User::factory()->create(['role' => 'student']);
        $studentC->assignRole('student');

        $date = now()->addDays(4)->format('Y-m-d');

        // Student A (06:00-09:00) and Student B (06:00-08:00) share an overlapping session at Spot A.
        $bookingA = Booking::factory()->create([
            'student_id' => $studentA->id,
            'instructor_id' => $l3->id,
            'surf_spot_id' => $spotA->id,
            'status' => BookingStatus::Confirmed,
            'skill_level' => SkillLevel::Beginner->value,
            'session_type' => SessionType::Teaching->value,
            'date' => $date,
            'time_period' => 'morning',
            'start_time' => '06:00',
            'end_time' => '09:00',
            'duration_hours' => 3,
        ]);

        $bookingB = Booking::factory()->create([
            'student_id' => $studentB->id,
            'instructor_id' => $l3->id,
            'surf_spot_id' => $spotA->id,
            'status' => BookingStatus::Confirmed,
            'skill_level' => SkillLevel::Beginner->value,
            'session_type' => SessionType::Teaching->value,
            'date' => $date,
            'time_period' => 'morning',
            'start_time' => '06:00',
            'end_time' => '08:00',
            'duration_hours' => 2,
        ]);

        // Student C is in a separate, non-overlapping afternoon slot on the same date at Spot A.
        $bookingC = Booking::factory()->create([
            'student_id' => $studentC->id,
            'instructor_id' => $l3->id,
            'surf_spot_id' => $spotA->id,
            'status' => BookingStatus::Confirmed,
            'skill_level' => SkillLevel::Beginner->value,
            'session_type' => SessionType::Teaching->value,
            'date' => $date,
            'time_period' => 'afternoon',
            'start_time' => '13:00',
            'end_time' => '15:00',
            'duration_hours' => 2,
        ]);

        // Safety Rule #5: cannot move Beginner students to an Advanced break.
        $this->actingAs($l3)
            ->patch(route('instructor.bookings.update-spot', $bookingA->id), [
                'surf_spot_id' => $advSpot->id,
                'reason' => 'Trying to move beginners to an advanced reef break.',
            ])
            ->assertSessionHas('error');

        $this->assertSame($spotA->id, $bookingA->fresh()->surf_spot_id);
        $this->assertSame($spotA->id, $bookingB->fresh()->surf_spot_id);

        // Moving to beginner Spot B updates BOTH overlapping bookings and notifies BOTH students with reason.
        $reason = 'Strong onshore wind at original spot; calmer clean waves at Burgos Inner.';
        $this->actingAs($l3)
            ->patch(route('instructor.bookings.update-spot', $bookingA->id), [
                'surf_spot_id' => $spotB->id,
                'reason' => $reason,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $bookingA->refresh();
        $bookingB->refresh();
        $bookingC->refresh();

        $this->assertSame($spotB->id, $bookingA->surf_spot_id);
        $this->assertSame($spotA->id, $bookingA->previous_surf_spot_id);
        $this->assertSame($reason, $bookingA->spot_change_reason);
        $this->assertNotNull($bookingA->spot_changed_at);

        $this->assertSame($spotB->id, $bookingB->surf_spot_id);
        $this->assertSame($spotA->id, $bookingB->previous_surf_spot_id);
        $this->assertSame($reason, $bookingB->spot_change_reason);
        $this->assertNotNull($bookingB->spot_changed_at);

        // Non-overlapping Student C stays at Spot A and is not notified.
        $this->assertSame($spotA->id, $bookingC->surf_spot_id);
        $this->assertNull($bookingC->spot_changed_at);

        Notification::assertSentTo($studentA, BookingSpotChangedNotification::class);
        Notification::assertSentTo($studentB, BookingSpotChangedNotification::class);
        Notification::assertNotSentTo($studentC, BookingSpotChangedNotification::class);
    }

    /** @test */
    public function instructor_cannot_reschedule_a_beginner_booking_onto_an_intermediate_slot(): void
    {
        $l3 = User::factory()->create(['role' => 'instructor']);
        $l3->assignRole('instructor');
        $l3->instructorProfile()->create([
            'level' => InstructorLevel::Level3,
            'status' => 'active',
            'rate_per_hour' => 800,
        ]);

        $otherStudent = User::factory()->create(['role' => 'student']);
        $otherStudent->assignRole('student');

        $targetDate = now()->addDays(6)->format('Y-m-d');
        $originalDate = now()->addDays(4)->format('Y-m-d');

        // Target slot is already held by an Intermediate Guiding booking.
        Booking::factory()->create([
            'student_id' => $otherStudent->id,
            'instructor_id' => $l3->id,
            'surf_spot_id' => $this->surfSpot->id,
            'status' => BookingStatus::Confirmed,
            'skill_level' => SkillLevel::Intermediate->value,
            'session_type' => SessionType::Guiding->value,
            'date' => $targetDate,
            'time_period' => 'morning',
            'start_time' => '07:00',
            'end_time' => '09:00',
            'duration_hours' => 2,
        ]);

        $beginnerBooking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $l3->id,
            'surf_spot_id' => $this->surfSpot->id,
            'status' => BookingStatus::Confirmed,
            'skill_level' => SkillLevel::Beginner->value,
            'session_type' => SessionType::Teaching->value,
            'date' => $originalDate,
            'time_period' => 'morning',
            'start_time' => '07:00',
            'end_time' => '09:00',
            'duration_hours' => 2,
        ]);

        $this->actingAs($l3)
            ->post(route('instructor.bookings.change-request', $beginnerBooking->id), [
                'type' => 'reschedule',
                'date' => $targetDate,
                'time_period' => 'morning',
                'start_time' => '07:00',
                'end_time' => '09:00',
                'reason' => 'Trying to merge Beginner onto Intermediate slot.',
            ])
            ->assertSessionHas('error');

        $this->assertSame($originalDate, $beginnerBooking->fresh()->date->format('Y-m-d'));
    }
}