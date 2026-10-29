<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\InstructorLevel;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\SurfSpot;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $instructor;
    private User $otherStudent;
    private SurfSpot $surfSpot;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        $this->student = User::factory()->create(['role' => 'student']);
        $this->student->assignRole('student');

        $this->otherStudent = User::factory()->create(['role' => 'student']);
        $this->otherStudent->assignRole('student');

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 1000,
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
            'student_age' => 25,
        ], $attributes));
    }

    public function test_confirming_a_booking_creates_one_conversation(): void
    {
        $booking = $this->booking();

        $this->assertDatabaseCount('conversations', 0);

        $booking->update(['status' => BookingStatus::Confirmed]);

        $this->assertDatabaseHas('conversations', [
            'booking_id' => $booking->id,
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
        ]);

        $this->assertSame(1, Conversation::where('booking_id', $booking->id)->count());
    }

    public function test_student_can_load_the_booking_conversation(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);

        $response = $this->actingAs($this->student)
            ->getJson(route('chat.index', $booking));

        $response->assertOk()
            ->assertJsonPath('conversation.booking_id', $booking->id)
            ->assertJsonPath('conversation.can_send', true)
            ->assertJsonPath('other_party.id', $this->instructor->id)
            ->assertJsonPath('messages', []);
    }

    public function test_instructor_can_send_a_message_and_student_sees_it(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);

        $this->actingAs($this->instructor)
            ->postJson(route('chat.store', $booking), [
                'body' => 'Meet me by the lifeguard tower at 7:45.',
            ])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Meet me by the lifeguard tower at 7:45.')
            ->assertJsonPath('message.mine', true);

        $this->assertDatabaseHas('messages', [
            'sender_id' => $this->instructor->id,
            'body' => 'Meet me by the lifeguard tower at 7:45.',
        ]);

        $this->actingAs($this->student)
            ->getJson(route('chat.index', $booking))
            ->assertOk()
            ->assertJsonPath('messages.0.body', 'Meet me by the lifeguard tower at 7:45.')
            ->assertJsonPath('messages.0.mine', false);
    }

    public function test_student_can_reply_and_instructor_sees_it(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);

        $this->actingAs($this->student)
            ->postJson(route('chat.store', $booking), ['body' => 'See you there.'])
            ->assertCreated();

        $this->actingAs($this->instructor)
            ->getJson(route('chat.index', $booking))
            ->assertOk()
            ->assertJsonPath('messages.0.body', 'See you there.');
    }

    public function test_an_unrelated_student_cannot_read_or_send(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);

        $this->actingAs($this->otherStudent)
            ->getJson(route('chat.index', $booking))
            ->assertForbidden();

        $this->actingAs($this->otherStudent)
            ->postJson(route('chat.store', $booking), ['body' => 'Let me in.'])
            ->assertForbidden();

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_chat_is_not_writable_before_confirmation(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->student)
            ->getJson(route('chat.index', $booking))
            ->assertOk()
            ->assertJsonPath('conversation', null)
            ->assertJsonPath('can_send', false);

        $this->actingAs($this->student)
            ->postJson(route('chat.store', $booking), ['body' => 'Too early.'])
            ->assertForbidden();
    }


    public function test_completed_bookings_keep_history_but_are_read_only(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);

        $this->actingAs($this->student)
            ->postJson(route('chat.store', $booking), ['body' => 'Great lesson plan.'])
            ->assertCreated();

        $booking->update(['status' => BookingStatus::Completed]);

        $this->actingAs($this->student)
            ->getJson(route('chat.index', $booking))
            ->assertOk()
            ->assertJsonPath('conversation.can_send', false)
            ->assertJsonPath('messages.0.body', 'Great lesson plan.');

        $this->actingAs($this->student)
            ->postJson(route('chat.store', $booking), ['body' => 'One more thing.'])
            ->assertForbidden();
    }

    public function test_reading_a_conversation_clears_the_unread_count(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);

        $this->actingAs($this->instructor)
            ->postJson(route('chat.store', $booking), ['body' => 'First message'])
            ->assertCreated();

        $this->actingAs($this->instructor)
            ->postJson(route('chat.store', $booking), ['body' => 'Second message'])
            ->assertCreated();

        $this->assertSame(
            2,
            app(ConversationService::class)->unreadCountForUser($this->student)
        );

        $this->actingAs($this->student)
            ->getJson(route('chat.index', $booking))
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(
            0,
            app(ConversationService::class)->unreadCountForUser($this->student)
        );
    }

    public function test_message_markup_is_stripped(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);

        $this->actingAs($this->student)
            ->postJson(route('chat.store', $booking), [
                'body' => '<script>alert(1)</script>Hello <b>there</b>',
            ])
            ->assertCreated()
            ->assertJsonPath('message.body', 'alert(1)Hello there');
    }

    public function test_message_body_is_required_and_limited(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);

        $this->actingAs($this->student)
            ->postJson(route('chat.store', $booking), ['body' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->actingAs($this->student)
            ->postJson(route('chat.store', $booking), ['body' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_booking_lists_and_sidebar_show_the_unread_count(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);

        $this->actingAs($this->instructor)
            ->postJson(route('chat.store', $booking), ['body' => 'Bring reef-safe sunscreen.'])
            ->assertCreated();

        $this->actingAs($this->student)
            ->get(route('student.bookings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('bookings.data.0.chat_unread_count', 1)
                ->where('nav.chatUnread', 1)
            );

        $this->actingAs($this->student)
            ->postJson(route('chat.store', $booking), ['body' => 'Will do.'])
            ->assertCreated();

        $this->actingAs($this->instructor)
            ->get(route('instructor.bookings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('bookings.data.0.chat_unread_count', 1)
                ->where('nav.chatUnread', 1)
            );
    }


}

