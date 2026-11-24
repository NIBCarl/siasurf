<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\InstructorLevel;
use App\Enums\PaymentStatus;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
use App\Events\SessionAttendanceMarked;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\SurfSpot;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\BookingChangeResolvedNotification;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\BookingRescheduledNotification;
use App\Notifications\RescheduleProposedNotification;
use App\Notifications\RescheduleResponseNotification;
use App\Notifications\SessionAttendanceNotification;
use App\Services\BookingAcceptanceService;
use App\Services\BookingChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $instructor;

    private User $admin;

    private SurfSpot $surfSpot;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');

        $this->student = User::factory()->student()->create();
        $this->student->assignRole('student');

        $this->instructor = User::factory()->instructor()->create();
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 1000,
        ]);

        $this->admin = User::factory()->admin()->create();
        $this->admin->assignRole('admin');

        $this->surfSpot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
    }

    private function pendingCashBooking(array $attributes = []): Booking
    {
        $booking = Booking::factory()->create(array_merge([
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
            'accept_deadline_at' => now()->addHours(2),
        ], $attributes));

        Payment::factory()->cash()->create([
            'booking_id' => $booking->id,
            'amount' => $booking->total_amount,
            'lesson_price' => $booking->lesson_price,
            'service_fee_amount' => $booking->service_fee_amount,
            'status' => PaymentStatus::PendingCash->value,
        ]);

        return $booking->fresh('payment');
    }

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

    public function test_marking_attendance_notifies_only_the_student_and_not_the_instructor(): void
    {
        Notification::fake();

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

        Notification::assertSentTo($this->student, SessionAttendanceNotification::class);
        Notification::assertNotSentTo($this->instructor, SessionAttendanceNotification::class);

        $channels = array_map(
            fn ($channel) => $channel->name,
            (new SessionAttendanceMarked($booking->fresh(), AttendanceStatus::Present))->broadcastOn()
        );

        $this->assertContains('private-student.'.$this->student->id, $channels);
        $this->assertContains('private-admin.notifications', $channels);
        $this->assertNotContains('private-instructor.'.$this->instructor->id, $channels);
    }

    public function test_manual_cash_accept_notifies_student_only(): void
    {
        Notification::fake();

        $booking = $this->pendingCashBooking();

        $this->actingAs($this->instructor)
            ->patch(route('instructor.bookings.confirm', $booking->id))
            ->assertRedirect();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        Notification::assertSentTo($this->student, BookingConfirmedNotification::class);
        Notification::assertNotSentTo($this->instructor, BookingConfirmedNotification::class);
    }

    public function test_instant_book_cash_accept_notifies_both_student_and_instructor(): void
    {
        Notification::fake();

        $booking = $this->pendingCashBooking();

        app(BookingAcceptanceService::class)->accept($booking, $this->student);

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        Notification::assertSentTo($this->student, BookingConfirmedNotification::class);
        Notification::assertSentTo($this->instructor, BookingConfirmedNotification::class);
    }

    public function test_instructor_auto_approved_cancellation_notifies_student_only(): void
    {
        Notification::fake();

        $booking = $this->confirmedBooking(['date' => now()->addDays(4)->format('Y-m-d')]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $booking->id), [
                'type' => 'cancellation',
                'reason' => 'Unsafe surf conditions.',
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->student, BookingCancelledNotification::class);
        Notification::assertNotSentTo($this->instructor, BookingCancelledNotification::class);
        Notification::assertNotSentTo($this->instructor, BookingChangeResolvedNotification::class);
        Notification::assertNotSentTo($this->student, BookingChangeResolvedNotification::class);
    }

    public function test_instructor_auto_approved_reschedule_notifies_student_only(): void
    {
        Notification::fake();

        $booking = $this->confirmedBooking(['date' => now()->addDays(4)->format('Y-m-d')]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $booking->id), [
                'type' => 'reschedule',
                'date' => now()->addDays(6)->format('Y-m-d'),
                'start_time' => '13:00',
                'reason' => 'Better tide window.',
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->student, RescheduleProposedNotification::class);
        Notification::assertNotSentTo($this->instructor, RescheduleProposedNotification::class);
        Notification::assertNotSentTo($this->instructor, BookingChangeResolvedNotification::class);
        Notification::assertNotSentTo($this->student, BookingChangeResolvedNotification::class);

        $this->actingAs($this->student)
            ->post(route('student.bookings.accept-reschedule', $booking->id))
            ->assertRedirect();

        Notification::assertSentTo($this->student, BookingRescheduledNotification::class);
        Notification::assertSentTo($this->instructor, RescheduleResponseNotification::class);
        Notification::assertNotSentTo($this->instructor, BookingChangeResolvedNotification::class);
        Notification::assertNotSentTo($this->student, BookingChangeResolvedNotification::class);
    }

    public function test_admin_approving_late_cancellation_notifies_student_cancelled_and_instructor_resolved_without_duplicates(): void
    {
        Notification::fake();

        $start = now()->addHours(3);
        $booking = $this->confirmedBooking([
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $booking->id), [
                'type' => 'cancellation',
                'reason' => 'Sudden injury.',
            ])
            ->assertRedirect();

        $changeRequest = $booking->changeRequests()->latest()->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.change-requests.approve', $changeRequest->id), [
                'admin_note' => 'Approved for safety.',
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->student, BookingCancelledNotification::class);
        Notification::assertNotSentTo($this->student, BookingChangeResolvedNotification::class);
        Notification::assertSentTo($this->instructor, BookingChangeResolvedNotification::class);
        Notification::assertNotSentTo($this->instructor, BookingCancelledNotification::class);
    }

    public function test_admin_rejecting_late_change_request_notifies_instructor_only(): void
    {
        Notification::fake();

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
            ])
            ->assertRedirect();

        $changeRequest = $booking->changeRequests()->latest()->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.change-requests.reject', $changeRequest->id), [
                'admin_note' => 'Too close to lesson start.',
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->instructor, BookingChangeResolvedNotification::class);
        Notification::assertNotSentTo($this->student, BookingChangeResolvedNotification::class);
    }

    public function test_instructor_withdrawing_change_request_does_not_self_notify(): void
    {
        Notification::fake();

        $start = now()->addHours(3);
        $booking = $this->confirmedBooking([
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
        ]);

        $changeRequest = app(BookingChangeService::class)->requestCancellation(
            $booking,
            $this->instructor,
            'Changed mind.'
        );

        app(BookingChangeService::class)->withdraw($changeRequest, $this->instructor);

        Notification::assertNotSentTo($this->instructor, BookingChangeResolvedNotification::class);
        Notification::assertNotSentTo($this->student, BookingChangeResolvedNotification::class);
    }
}
