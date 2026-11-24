<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\InstructorLevel;
use App\Enums\WaiverType;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use App\Models\Waiver;
use App\Notifications\ParentalConsentRecordedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Single-account booking rules, shared-slot group joining, and verifiable
 * minor parental consent:
 *  - Each booking reserves 1 seat for the signed-in student account;
 *  - Multi-student / companion payloads are rejected at validation;
 *  - Separate registered accounts share Level 2/3 slots via shared slot links;
 *  - Minors (< 18) require parental consent with relationship + guardian email
 *    notification carrying the signed PDF copy.
 */
class BookingParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $instructor;
    private SurfSpot $surfSpot;

    private string $validPngDataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        $this->student = User::factory()->create(['role' => 'student']);
        $this->student->assignRole('student');
        $this->student->studentProfile()->create([
            'skill_level' => 'intermediate',
            'is_first_time' => false,
            'status' => 'active',
        ]);

        // Level 3 can host up to 3 separate same-level student accounts in one slot.
        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level3,
            'status' => 'active',
            'rate_per_hour' => 800,
            'guiding_rate' => 1200,
            'coaching_rate' => 1800,
            'auto_accept_bookings' => false,
        ]);

        $this->surfSpot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'date' => now()->addDays(3)->format('Y-m-d'),
            'start_time' => '09:00',
            'skill_level' => 'beginner', // deliberately wrong; profile must win
            'student_age' => 30,
            'height' => 175,
            'weight' => 70,
            'student_count' => 1,
            'has_board' => false,
            'surf_spot_id' => $this->surfSpot->id,
        ], $overrides);
    }

    /** @test */
    public function the_profile_skill_level_overrides_what_the_client_sends()
    {
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor->id), $this->basePayload())
            ->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();

        // Profile is intermediate; the payload lied and said beginner.
        $this->assertSame('intermediate', $booking->skill_level->value);
        $this->assertSame('intermediate', $booking->primaryParticipant->skill_level->value);
        $this->assertSame(1, $booking->student_count);
    }

    /** @test */
    public function multiple_students_or_companions_in_a_single_booking_are_rejected()
    {
        $payload = $this->basePayload([
            'student_count' => 2,
            'participants' => [
                [
                    'full_name' => 'Primary Surfer',
                    'age' => 30,
                    'height' => 175,
                    'weight' => 70,
                    'skill_level' => 'intermediate',
                    'has_board' => false,
                ],
                [
                    'full_name' => 'Companion One',
                    'age' => 28,
                    'height' => 160,
                    'weight' => 55,
                    'skill_level' => 'intermediate',
                    'has_board' => true,
                ],
            ],
        ]);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor->id), $payload)
            ->assertSessionHasErrors(['student_count', 'participants']);

        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor->id), $this->basePayload([
                'companions' => [
                    ['full_name' => 'Guest Friend'],
                ],
            ]))
            ->assertSessionHasErrors('companions');

        $this->assertDatabaseCount('bookings', 0);
    }

    /** @test */
    public function separate_registered_students_can_share_a_level_three_slot_and_receive_slot_share_link()
    {
        $friend = User::factory()->create(['role' => 'student']);
        $friend->assignRole('student');
        $friend->studentProfile()->create([
            'skill_level' => 'intermediate',
            'is_first_time' => false,
            'status' => 'active',
        ]);

        $date = now()->addDays(3)->format('Y-m-d');

        // Student 1 books 1 seat in the Level 3 slot and signs their own waiver.
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor->id), $this->basePayload([
                'date' => $date,
                'start_time' => '09:00',
                'end_time' => '11:00',
            ]))
            ->assertRedirect();

        $booking1 = Booking::latest('id')->firstOrFail();

        $this->actingAs($this->student)
            ->post(route('bookings.store-waiver', $booking1), [
                'emergency_contact' => 'Jane Doe - +63 917 000 0000',
                'signed_by' => $this->student->name,
                'signature' => $this->validPngDataUri,
                'agreement' => true,
                'participant_id' => $booking1->primaryParticipant->id,
            ])
            ->assertRedirect(route('bookings.payment', $booking1));

        $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking1), ['payment_method' => 'cash'])
            ->assertRedirect(route('bookings.confirmation', $booking1));

        // Confirmation exposes the shared-slot link with 2 of 3 seats remaining.
        $this->actingAs($this->student)
            ->get(route('bookings.confirmation', $booking1))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Booking/Confirmation')
                ->where('slotShare.max_seats', 3)
                ->where('slotShare.remaining_seats', 2)
                ->where('slotShare.share_url', route('bookings.create', [
                    'instructor' => $this->instructor->id,
                    'date' => $date,
                    'start_time' => '09:00',
                ]))
            );

        // Friend opens the shared link and sees date/start_time prefilled.
        $this->actingAs($friend)
            ->get(route('bookings.create', [
                'instructor' => $this->instructor->id,
                'date' => $date,
                'start_time' => '09:00',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Booking/Create')
                ->where('initialDate', $date)
                ->where('initialStartTime', '09:00')
            );

        // Friend books the 2nd seat from their own registered account.
        $this->actingAs($friend)
            ->post(route('bookings.store-details', $this->instructor->id), $this->basePayload([
                'date' => $date,
                'start_time' => '09:00',
                'end_time' => '11:00',
            ]))
            ->assertRedirect();

        $booking2 = Booking::latest('id')->firstOrFail();

        $this->assertSame(2, Booking::where('instructor_id', $this->instructor->id)->count());

        // Booking detail page now reports 1 of 3 seats remaining.
        $this->actingAs($this->student)
            ->get(route('student.bookings.show', $booking1))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/Bookings/Show')
                ->where('slotShare.max_seats', 3)
                ->where('slotShare.remaining_seats', 1)
            );
        $this->assertSame($friend->id, $booking2->student_id);
    }

    /** @test */
    public function minor_student_requires_level_three_and_parental_consent_emails_guardian_pdf()
    {
        Notification::fake();

        $levelOne = User::factory()->create(['role' => 'instructor']);
        $levelOne->assignRole('instructor');
        $levelOne->instructorProfile()->create([
            'level' => InstructorLevel::Level1,
            'status' => 'active',
            'rate_per_hour' => 600,
        ]);

        $minor = User::factory()->create([
            'name' => 'Leo Santos',
            'email' => 'leo@example.com',
            'role' => 'student',
        ]);
        $minor->assignRole('student');
        $minor->studentProfile()->create([
            'skill_level' => 'beginner',
            'birthday' => now()->subYears(10)->format('Y-m-d'),
            'country' => 'Philippines',
            'is_first_time' => true,
            'status' => 'active',
        ]);

        // Age 5-12 cannot book a Level 1 instructor.
        $this->actingAs($minor)
            ->post(route('bookings.store-details', $levelOne->id), $this->basePayload([
                'student_age' => 10,
                'session_type' => 'teaching',
            ]))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('bookings', 0);

        // Age 10 can book a Level 3 instructor.
        $this->actingAs($minor)
            ->post(route('bookings.store-details', $this->instructor->id), $this->basePayload([
                'student_age' => 10,
                'session_type' => 'teaching',
            ]))
            ->assertRedirect();

        $booking = Booking::with('primaryParticipant')->latest('id')->firstOrFail();
        $participant = $booking->primaryParticipant;

        // Liability waiver advances to parental consent.
        $this->actingAs($minor)
            ->post(route('bookings.store-waiver', $booking), [
                'emergency_contact' => 'Maria Santos - +63 917 111 2222',
                'signed_by' => 'Maria Santos',
                'signature' => $this->validPngDataUri,
                'agreement' => true,
                'participant_id' => $participant->id,
            ])
            ->assertRedirect(route('bookings.parental-consent', $booking));

        // Missing relationship and guardian email must fail validation.
        $this->actingAs($minor)
            ->post(route('bookings.store-parental-consent', $booking), [
                'parent_name' => 'Maria Santos',
                'parent_signature' => $this->validPngDataUri,
                'emergency_contact_name' => 'Maria Santos',
                'emergency_contact_phone' => '+63 917 111 2222',
                'agreement' => true,
                'participant_id' => $participant->id,
            ])
            ->assertSessionHasErrors(['guardian_relationship', 'guardian_email']);

        // Submitting valid guardian relationship & email succeeds and emails the guardian.
        $this->actingAs($minor)
            ->post(route('bookings.store-parental-consent', $booking), [
                'parent_name' => 'Maria Santos',
                'guardian_relationship' => 'Mother',
                'guardian_email' => 'Maria.Guardian@Example.com',
                'parent_signature' => $this->validPngDataUri,
                'emergency_contact_name' => 'Maria Santos',
                'emergency_contact_phone' => '+63 917 111 2222',
                'medical_info' => 'No allergies',
                'agreement' => true,
                'participant_id' => $participant->id,
            ])
            ->assertRedirect(route('bookings.payment', $booking));

        $consent = Waiver::where('booking_id', $booking->id)
            ->where('type', WaiverType::ParentalConsent->value)
            ->firstOrFail();

        $this->assertSame('Leo Santos', $consent->participant_name);
        $this->assertSame(10, $consent->age);
        $this->assertSame('Mother', $consent->guardian_relationship);
        $this->assertSame('maria.guardian@example.com', $consent->guardian_email);
        $this->assertNotNull($consent->guardian_notified_at);
        $this->assertTrue($consent->signed_by_guardian);
        Storage::disk('local')->assertExists($consent->pdf_path);

        $participant->refresh();
        $this->assertSame('Mother', $participant->guardian_relationship);
        $this->assertSame('maria.guardian@example.com', $participant->guardian_email);

        Notification::assertSentOnDemand(
            ParentalConsentRecordedNotification::class,
            function (ParentalConsentRecordedNotification $notification, array $channels, AnonymousNotifiable $notifiable) {
                $mail = $notification->toMail($notifiable);

                return in_array('mail', $channels, true)
                    && ($notifiable->routes['mail'] ?? null) === 'maria.guardian@example.com'
                    && count($mail->rawAttachments) === 1
                    && str_contains($mail->subject, 'Leo Santos');
            }
        );

        $this->assertTrue(app(\App\Services\WaiverService::class)->hasRequiredWaivers($booking->fresh()));
    }

    /** @test */
    public function payment_is_blocked_until_required_waivers_are_signed()
    {
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor->id), $this->basePayload())
            ->assertRedirect();

        $booking = Booking::with('participants')->latest('id')->firstOrFail();
        $primary = $booking->participants[0];

        // Payment without signing liability bounces back to waiver.
        $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking), ['payment_method' => 'cash'])
            ->assertRedirect(route('bookings.waiver', $booking));

        $this->assertSame(BookingStatus::Reserved, $booking->fresh()->status);

        // Sign liability waiver, then payment succeeds.
        $this->actingAs($this->student)->post(
            route('bookings.store-waiver', $booking),
            [
                'emergency_contact' => 'Jane Doe - +63 917 000 0000',
                'signed_by' => $this->student->name,
                'signature' => $this->validPngDataUri,
                'agreement' => true,
                'participant_id' => $primary->id,
            ]
        )->assertRedirect(route('bookings.payment', $booking));

        $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking), ['payment_method' => 'cash'])
            ->assertRedirect(route('bookings.confirmation', $booking));

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }
}
