<?php

namespace Tests\Feature;

use App\Enums\InstructorStatus;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\InstructorRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The notification bell reads a persisted feed, so history survives the layout
 * remount that happens on every Inertia visit.
 */
class NotificationFeedTest extends TestCase
{
    use RefreshDatabase;

    private function notifiableUser(): User
    {
        $user = User::factory()->student()->create();

        $booking = Booking::factory()->create(['student_id' => $user->id]);

        return tap($user, fn (User $u) => $u->notify(
            new BookingCancelledNotification(
                $booking->load(['student', 'instructor', 'surfSpot']),
                'Automated check.',
            )
        ));
    }

    public function test_feed_returns_the_signed_in_users_notifications(): void
    {
        $user = $this->notifiableUser();

        $response = $this->actingAs($user->fresh())->getJson(route('notifications.index'));

        $response->assertOk();
        $response->assertJsonPath('unread', 1);
        $this->assertCount(1, $response->json('items'));

        $item = $response->json('items.0');

        $this->assertSame('booking_cancelled', $item['type']);
        $this->assertStringContainsString('Automated check.', $item['message']);
        $this->assertFalse($item['read']);
    }

    public function test_feed_does_not_leak_another_users_notifications(): void
    {
        $owner = $this->notifiableUser();
        $other = User::factory()->student()->create();

        $response = $this->actingAs($other)->getJson(route('notifications.index'));

        $response->assertOk();
        $response->assertJsonPath('unread', 0);
        $this->assertSame([], $response->json('items'));
    }

    public function test_a_notification_can_be_marked_as_read(): void
    {
        $user = $this->notifiableUser();
        $id = $user->notifications()->first()->id;

        $response = $this->actingAs($user->fresh())
            ->postJson(route('notifications.read', $id));

        $response->assertOk();
        $response->assertJsonPath('unread', 0);
        $this->assertTrue($response->json('items.0.read'));
        $this->assertNotNull($user->notifications()->first()->fresh()->read_at);
    }

    public function test_all_notifications_can_be_marked_as_read(): void
    {
        $user = $this->notifiableUser();

        $response = $this->actingAs($user->fresh())->postJson(route('notifications.read-all'));

        $response->assertOk();
        $response->assertJsonPath('unread', 0);
        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    public function test_a_notification_can_be_dismissed(): void
    {
        $user = $this->notifiableUser();
        $id = $user->notifications()->first()->id;

        $response = $this->actingAs($user->fresh())
            ->deleteJson(route('notifications.destroy', $id));

        $response->assertOk();
        $this->assertSame(0, $user->fresh()->notifications()->count());
    }

    public function test_all_notifications_can_be_cleared(): void
    {
        $user = $this->notifiableUser();

        $response = $this->actingAs($user->fresh())->deleteJson(route('notifications.clear'));

        $response->assertOk();
        $this->assertSame(0, $user->fresh()->notifications()->count());
    }

    public function test_a_user_cannot_touch_another_users_notification(): void
    {
        $owner = $this->notifiableUser();
        $intruder = User::factory()->student()->create();
        $id = $owner->notifications()->first()->id;

        $this->actingAs($intruder)
            ->postJson(route('notifications.read', $id))
            ->assertNotFound();

        $this->actingAs($intruder)
            ->deleteJson(route('notifications.destroy', $id))
            ->assertNotFound();

        $this->assertSame(1, $owner->fresh()->notifications()->count());
    }

    public function test_guests_cannot_reach_the_feed(): void
    {
        $this->getJson(route('notifications.index'))->assertUnauthorized();
    }

    public function test_feed_includes_rejection_reason_for_new_and_legacy_instructor_rejected_notifications(): void
    {
        $instructor = User::factory()->instructor()->create();
        $instructor->instructorProfile()->create([
            'bio' => 'Verified instructor',
            'level' => 1,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => 600,
        ]);

        // Legacy row where `message` omitted `reason` and `url`.
        $instructor->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => InstructorRejected::class,
            'data' => [
                'type' => 'instructor_rejected',
                'reason' => 'Blurry ID photo.',
                'message' => 'Your instructor application was not approved.',
            ],
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        // New notification written via InstructorRejected::toArray().
        $instructor->notify(new InstructorRejected('Missing BLS certificate.'));

        $response = $this->actingAs($instructor->fresh())->getJson(route('notifications.index'));

        $response->assertOk();
        $response->assertJsonPath('items.0.type', 'instructor_rejected');
        $response->assertJsonPath('items.0.url', route('instructor.onboarding'));
        $response->assertJsonPath(
            'items.0.message',
            'Your instructor application was not approved. Reason: Missing BLS certificate.'
        );
        $response->assertJsonPath('items.1.type', 'instructor_rejected');
        $response->assertJsonPath('items.1.url', route('instructor.onboarding'));
        $response->assertJsonPath(
            'items.1.message',
            'Your instructor application was not approved. Reason: Blurry ID photo.'
        );
    }
}
