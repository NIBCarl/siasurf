<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\InstructorProfile;
use App\Models\User;
use App\Notifications\BookingRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for the reported bug: a student books an instructor and the
 * instructor's bell stayed empty because the request was broadcast but never
 * persisted.
 */
class BookingRequestNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function instructorWithProfile(): User
    {
        $instructor = User::factory()->instructor()->create();
        InstructorProfile::factory()->create([
            'user_id' => $instructor->id,
            'status' => 'active',
        ]);

        return $instructor;
    }

    public function test_a_new_booking_request_persists_a_notification_for_the_instructor(): void
    {
        $instructor = $this->instructorWithProfile();
        $student = User::factory()->student()->create();
        $booking = Booking::factory()->create([
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
        ]);

        $booking->load(['student', 'instructor', 'surfSpot']);
        $instructor->notify(new BookingRequestedNotification($booking));

        $this->assertSame(1, $instructor->fresh()->notifications()->count());

        $row = $instructor->notifications()->first();

        $this->assertSame('booking_requested', $row->data['type']);
        $this->assertSame($booking->id, $row->data['booking_id']);
        $this->assertNotNull($row->data['url']);
        $this->assertStringContainsString(
            "/instructor/bookings/{$booking->id}",
            $row->data['url'],
        );
    }

    public function test_the_notification_body_names_the_student(): void
    {
        $instructor = $this->instructorWithProfile();
        $student = User::factory()->student()->create(['name' => 'Ana Reyes']);
        $booking = Booking::factory()->create([
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
        ]);

        $instructor->notify(new BookingRequestedNotification(
            $booking->load(['student', 'instructor', 'surfSpot'])
        ));

        $message = $instructor->notifications()->first()->data['message'];

        $this->assertStringContainsString('Ana Reyes', $message);
    }

    public function test_the_student_does_not_receive_the_instructors_notification(): void
    {
        $instructor = $this->instructorWithProfile();
        $student = User::factory()->student()->create();
        $booking = Booking::factory()->create([
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
        ]);

        $instructor->notify(new BookingRequestedNotification(
            $booking->load(['student', 'instructor', 'surfSpot'])
        ));

        $this->assertSame(0, $student->fresh()->notifications()->count());
    }

    public function test_the_feed_exposes_a_clickable_url_for_the_instructor(): void
    {
        $instructor = $this->instructorWithProfile();
        $student = User::factory()->student()->create();
        $booking = Booking::factory()->create([
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
        ]);

        $instructor->notify(new BookingRequestedNotification(
            $booking->load(['student', 'instructor', 'surfSpot'])
        ));

        $response = $this->actingAs($instructor->fresh())
            ->getJson(route('notifications.index'));

        $response->assertOk();
        $response->assertJsonPath('unread', 1);

        $item = $response->json('items.0');

        $this->assertSame('booking_requested', $item['type']);
        $this->assertStringContainsString("/instructor/bookings/{$booking->id}", $item['url']);
    }

    public function test_the_notification_is_stored_before_mail_is_attempted(): void
    {
        $notification = new BookingRequestedNotification(
            Booking::factory()->create([
                'instructor_id' => User::factory()->instructor()->create()->id,
                'student_id' => User::factory()->student()->create()->id,
            ])->load(['student', 'instructor', 'surfSpot'])
        );

        // The bell must not wait on SMTP, so database has to come first.
        $this->assertSame(['database', 'mail'], $notification->via(new User));
    }
}
