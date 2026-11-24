<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\InstructorLevel;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\SurfSpot;
use App\Models\User;
use App\Notifications\BookingRescheduledNotification;
use App\Notifications\RescheduleProposedNotification;
use App\Services\BookingEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BookingAttendanceRescheduleAndManifestTest extends TestCase
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

    private function createBooking(array $attributes = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->surfSpot->id,
            'status' => BookingStatus::Confirmed,
            'date' => now()->addDays(4)->format('Y-m-d'),
            'original_date' => now()->addDays(4)->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
            'student_age' => 25,
            'student_count' => 1,
            'skill_level' => 'beginner',
            'session_type' => 'teaching',
            'total_amount' => 2000,
        ], $attributes));
    }

    public function test_instructor_reschedule_availability_returns_automated_slots_excluding_self(): void
    {
        $date = now()->addDays(5)->format('Y-m-d');
        $booking = $this->createBooking([
            'date' => $date,
            'original_date' => $date,
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
        ]);

        $response = $this->actingAs($this->instructor)
            ->getJson(route('instructor.bookings.reschedule-availability', [
                'booking' => $booking->id,
                'date' => $date,
            ]));

        $response->assertOk()
            ->assertJsonPath('duration_hours', 2)
            ->assertJsonPath('has_any_slot', true);

        $morningSlots = collect($response->json('periods.morning.slots'));
        $this->assertTrue($morningSlots->contains(fn ($s) => $s['start_time'] === '08:00' && $s['is_current'] === true));
        // 11:00 + 2h = 13:00 crosses into afternoon, so 11:00 must not be offered for a 2h booking.
        $this->assertFalse($morningSlots->contains(fn ($s) => $s['start_time'] === '11:00'));
    }

    public function test_reschedule_posts_reason_in_messages_allows_conversation_and_shows_rescheduled_to_date(): void
    {
        Notification::fake();

        $oldDate = now()->addDays(4)->format('Y-m-d');
        $newDate = now()->addDays(7)->format('Y-m-d');
        $booking = $this->createBooking([
            'date' => $oldDate,
            'original_date' => $oldDate,
            'status' => BookingStatus::Pending,
            'accept_deadline_at' => now()->addHours(2),
        ]);

        // Even while Pending, proposing a reschedule opens the chat with the reason.
        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $booking->id), [
                'type' => 'reschedule',
                'date' => $newDate,
                'start_time' => '14:00',
                'reason' => 'Strong swell expected on Friday morning.',
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->student, RescheduleProposedNotification::class);

        $conversation = Conversation::where('booking_id', $booking->id)->firstOrFail();
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $this->instructor->id,
        ]);

        // Student can reply in chat before agreeing.
        $this->actingAs($this->student)
            ->postJson(route('chat.store', $booking->id), [
                'body' => '2 PM works great for me!',
            ])
            ->assertCreated();

        // Schedule still on old date until student agrees.
        $this->assertSame($oldDate, $booking->fresh()->date->format('Y-m-d'));

        // Student agrees -> booking moves and student views show Rescheduled to {date}.
        $this->actingAs($this->student)
            ->post(route('student.bookings.accept-reschedule', $booking->id))
            ->assertRedirect();

        Notification::assertSentTo($this->student, BookingRescheduledNotification::class);

        $fresh = $booking->fresh();
        $this->assertSame($newDate, $fresh->date->format('Y-m-d'));
        $this->assertSame('afternoon', $fresh->time_period->value);
        $this->assertSame('14:00', $fresh->start_time->format('H:i'));
        $this->assertSame('16:00', $fresh->end_time->format('H:i'));
        $this->assertNotNull($fresh->rescheduled_by_instructor_at);

        $this->actingAs($this->student)
            ->get(route('student.bookings.show', $booking->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.is_rescheduled', true)
                ->where('booking.date', $newDate)
            );
    }

    public function test_student_declining_reschedule_keeps_original_schedule(): void
    {
        $oldDate = now()->addDays(4)->format('Y-m-d');
        $newDate = now()->addDays(8)->format('Y-m-d');
        $booking = $this->createBooking([
            'date' => $oldDate,
            'original_date' => $oldDate,
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $booking->id), [
                'type' => 'reschedule',
                'date' => $newDate,
                'start_time' => '09:00',
                'reason' => 'Board maintenance.',
            ])
            ->assertRedirect();

        $this->actingAs($this->student)
            ->post(route('student.bookings.decline-reschedule', $booking->id))
            ->assertRedirect();

        $this->assertSame($oldDate, $booking->fresh()->date->format('Y-m-d'));
        $this->assertDatabaseHas('booking_change_requests', [
            'booking_id' => $booking->id,
            'status' => ChangeRequestStatus::Rejected->value,
        ]);
    }

    public function test_attendance_then_complete_session_works_without_start_session(): void
    {
        $start = now()->subMinutes(20);
        $booking = $this->createBooking([
            'status' => BookingStatus::Confirmed,
            'date' => $start->format('Y-m-d'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->copy()->addHours(2)->format('H:i'),
            'duration_hours' => 2,
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.attendance', $booking->id), [
                'attendance_status' => AttendanceStatus::Present->value,
            ])
            ->assertRedirect();

        $this->assertSame(BookingStatus::InProgress, $booking->fresh()->status);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.complete-session', $booking->id))
            ->assertRedirect();

        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);
    }

    public function test_admin_bookings_manifest_orders_by_latest_action_filters_rescheduled_and_logs_completed_events(): void
    {
        $olderBooking = $this->createBooking([
            'status' => BookingStatus::Pending,
            'created_at' => now()->subDays(2),
            'last_event_at' => now()->subDays(2),
        ]);

        $newerBooking = $this->createBooking([
            'status' => BookingStatus::Pending,
            'created_at' => now()->subDay(),
            'last_event_at' => now()->subDay(),
        ]);

        app(BookingEventService::class)->recordCreated($olderBooking);
        app(BookingEventService::class)->recordCreated($newerBooking);

        // Newer booking is on top initially.
        $this->actingAs($this->admin)
            ->get(route('admin.bookings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('bookings.data.0.id', $newerBooking->id)
            );

        // Older booking completes confirmation, payment, and rescheduling -> bubbles to top.
        $olderBooking->update([
            'status' => BookingStatus::AwaitingPayment,
            'payment_status' => PaymentStatus::Pending->value,
        ]);
        Payment::create([
            'booking_id' => $olderBooking->id,
            'amount' => 2000,
            'payment_method' => PaymentMethod::GCash->value,
            'status' => PaymentStatus::PendingVerification->value,
            'receipt_path' => 'receipts/test.png',
        ]);

        $this->actingAs($this->admin)
            ->post(route('payments.receipt.verify', $olderBooking->id))
            ->assertRedirect();

        $newDate = now()->addDays(9)->format('Y-m-d');
        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $olderBooking->id), [
                'type' => 'reschedule',
                'date' => $newDate,
                'start_time' => '10:00',
                'reason' => 'High tide adjustment.',
            ])
            ->assertRedirect();

        $this->actingAs($this->student)
            ->post(route('student.bookings.accept-reschedule', $olderBooking->id))
            ->assertRedirect();

        $this->assertDatabaseHas('booking_events', [
            'booking_id' => $olderBooking->id,
            'event_type' => BookingEvent::TYPE_PAYMENT_COMPLETED,
        ]);
        $this->assertDatabaseHas('booking_events', [
            'booking_id' => $olderBooking->id,
            'event_type' => BookingEvent::TYPE_CONFIRMED,
        ]);
        $this->assertDatabaseHas('booking_events', [
            'booking_id' => $olderBooking->id,
            'event_type' => BookingEvent::TYPE_RESCHEDULED,
        ]);

        // Manifest now puts olderBooking (latest action) on top and populates recentEvents.
        $this->actingAs($this->admin)
            ->get(route('admin.bookings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('bookings.data.0.id', $olderBooking->id)
                ->where('bookings.data.0.is_rescheduled', true)
                ->where('recentEvents.0.booking_id', $olderBooking->id)
                ->where('recentEvents.0.event_type', BookingEvent::TYPE_RESCHEDULED)
            );

        // Rescheduled filter shows only rescheduled bookings.
        $this->actingAs($this->admin)
            ->get(route('admin.bookings.index', ['status' => 'rescheduled']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('bookings.data', 1)
                ->where('bookings.data.0.id', $olderBooking->id)
            );

        // Admin booking dossier loads real events.
        $this->actingAs($this->admin)
            ->get(route('admin.bookings.show', $olderBooking->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.is_rescheduled', true)
                ->has('booking.events', 4)
            );
    }
}
