<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\InstructorLevel;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use App\Services\BookingChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The free-text cancellation feedback (instructor reason + SISA note) must be
 * stored on the booking and visible to the student, not buried in `notes`.
 */
class CancellationFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $instructor;
    private User $admin;
    private SurfSpot $spot;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', 'instructor', 'admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->student = User::factory()->create(['role' => 'student']);
        $this->student->assignRole('student');
        $this->student->studentProfile()->create([
            'skill_level' => 'beginner',
            'is_first_time' => false,
            'status' => 'active',
        ]);

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 1000,
        ]);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('admin');

        $this->spot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
    }

    private function booking(array $overrides = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'date' => now()->addDays(3)->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
            'student_age' => 25,
            'student_count' => 1,
            'status' => BookingStatus::Confirmed,
            'notes' => 'Bring a rash guard.',
        ], $overrides));
    }

    /** @test */
    public function a_student_cancellation_keeps_the_reason_out_of_the_special_requests(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->student)
            ->post(route('student.bookings.cancel', $booking), ['reason' => 'My flight moved.'])
            ->assertRedirect();

        $booking->refresh();

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(CancellationReason::Student, $booking->cancellation_reason);
        $this->assertSame('My flight moved.', $booking->cancellation_note);
        $this->assertSame('Bring a rash guard.', $booking->notes);
        $this->assertSame('Cancelled by student', $booking->cancellation_label);
    }

    /** @test */
    public function the_instructor_reason_is_visible_on_the_student_booking_page(): void
    {
        $booking = $this->booking();

        app(BookingChangeService::class)->requestCancellation(
            $booking,
            $this->instructor,
            'Typhoon swell made the break unsafe.'
        );

        $booking->refresh();

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame('Typhoon swell made the break unsafe.', $booking->cancellation_note);

        $this->actingAs($this->student)
            ->get(route('student.bookings.show', $booking))
            ->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $page
                ->where('booking.cancellation_note', 'Typhoon swell made the break unsafe.')
                ->where('booking.cancellation_label', 'Cancelled by instructor')
            );
    }

    /** @test */
    public function an_admin_note_is_added_to_the_student_facing_cancellation_feedback(): void
    {
        // Inside the 24-hour window, so SISA must approve it.
        $booking = $this->booking([
            'date' => now()->format('Y-m-d'),
            'start_time' => now()->addHours(3)->format('H:i'),
            'end_time' => now()->addHours(5)->format('H:i'),
        ]);

        $service = app(BookingChangeService::class);

        $changeRequest = $service->requestCancellation(
            $booking,
            $this->instructor,
            'The instructor was injured.'
        );

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        $service->approve($changeRequest, $this->admin, 'Approved: safety first.');

        $booking->refresh();

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertStringContainsString('The instructor was injured.', $booking->cancellation_note);
        $this->assertStringContainsString('SISA note: Approved: safety first.', $booking->cancellation_note);

        $this->actingAs($this->student)
            ->get(route('student.bookings.show', $booking))
            ->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $page
                ->where('booking.latest_change_request.admin_note', 'Approved: safety first.')
            );
    }
}
