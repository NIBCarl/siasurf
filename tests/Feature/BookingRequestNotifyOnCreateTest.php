<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\InstructorLevel;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use App\Models\Waiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The staff-critical regression: a student books a normal instructor (request
 * queue, not Instant Book) and the instructor's persisted feed must gain an
 * entry. Previously only a broadcast fired, so the bell stayed empty.
 *
 * The notify path lives in the payment step, because that is the moment the
 * booking leaves the student's 15-minute checkout hold and enters the
 * instructor's 2-hour acceptance window.
 */
class BookingRequestNotifyOnCreateTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    private SurfSpot $surfSpot;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        $this->instructor = User::factory()->create([
            'role' => 'instructor',
            'email_verified_at' => now(),
        ]);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level3,
            'status' => 'active',
            'rate_per_hour' => 1500,
            // Explicit: a normal instructor uses the request queue.
            'auto_accept_bookings' => false,
        ]);

        $this->student = User::factory()->create([
            'role' => 'student',
            'email_verified_at' => now(),
        ]);
        $this->student->assignRole('student');

        $this->surfSpot = SurfSpot::factory()->create([
            'difficulty' => 'beginner',
            'is_active' => true,
        ]);

        foreach (range(0, 6) as $day) {
            foreach (['morning', 'afternoon'] as $period) {
                Availability::create([
                    'user_id' => $this->instructor->id,
                    'day_of_week' => $day,
                    'time_period' => $period,
                    'is_available' => true,
                ]);
            }
        }
    }

    private function booking(): Booking
    {
        $booking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->surfSpot->id,
            'status' => BookingStatus::Pending,
            'date' => now()->addDays(3)->toDateString(),
            'time_period' => 'morning',
            'skill_level' => 'beginner',
            'student_count' => 1,
        ]);

        Waiver::create([
            'booking_id' => $booking->id,
            'type' => 'liability',
            'signed_by' => $this->student->name,
            'signature_data' => 'data:image/png;base64,AAAA',
            'pdf_path' => "waivers/liability_booking_{$booking->id}.pdf",
            'signed_at' => now(),
            'retention_until' => now()->addYears(7),
        ]);

        return $booking;
    }

    public function test_a_booking_request_persists_a_notification_for_the_instructor(): void
    {
        $booking = $this->booking();

        $this->assertSame(0, $this->instructor->notifications()->count());

        $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking->id), ['payment_method' => 'cash']);

        $fresh = $this->instructor->fresh();

        $this->assertSame(
            1,
            $fresh->notifications()->count(),
            'The instructor must get a persisted notification when a student books them.',
        );

        $row = $fresh->notifications()
            ->where('data->type', 'booking_requested')
            ->first();

        $this->assertNotNull($row, 'The booking request notification was not persisted.');

        $this->assertSame('booking_requested', $row->data['type']);
        $this->assertSame($booking->id, $row->data['booking_id']);
        $this->assertStringContainsString("/instructor/bookings/{$booking->id}", $row->data['url']);
    }

    public function test_the_student_does_not_notify_themselves(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking->id), ['payment_method' => 'cash']);

        $this->assertSame(0, $this->student->fresh()->notifications()->count());
    }

    public function test_the_feeds_badge_counts_the_new_request(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking->id), ['payment_method' => 'cash']);

        $response = $this->actingAs($this->instructor->fresh())
            ->getJson(route('notifications.index'));

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('unread'));

        // The intent is "the instructor sees the new request", not an exact
        // total, so do not couple this to unrelated notification traffic.
        $types = array_column($response->json('items'), 'type');
        $this->assertContains('booking_requested', $types);
    }
}
