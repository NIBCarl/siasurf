<?php

namespace Tests\Feature;

use App\Enums\InstructorLevel;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use App\Models\Waiver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SisaWaiverAndHotelPickupTest extends TestCase
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

        $this->student = User::factory()->create([
            'name' => 'Alex Rivera',
            'email' => 'alex@example.com',
            'role' => 'student',
        ]);
        $this->student->assignRole('student');
        $this->student->studentProfile()->create([
            'skill_level' => 'beginner',
            'is_first_time' => true,
            'birthday' => '1999-05-15',
            'country' => 'Australia',
            'status' => 'active',
        ]);

        $this->instructor = User::factory()->create([
            'name' => 'Coach Kai Santos',
            'role' => 'instructor',
        ]);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 800,
        ]);

        $this->surfSpot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
    }

    private function baseBookingPayload(array $overrides = []): array
    {
        return array_merge([
            'date' => now()->addDays(3)->format('Y-m-d'),
            'start_time' => '09:00',
            'student_age' => Carbon::parse('1999-05-15')->age,
            'height' => 172,
            'weight' => 68,
            'student_count' => 1,
            'has_board' => false,
            'surf_spot_id' => $this->surfSpot->id,
            'needs_hotel_pickup' => false,
            'hotel_name' => null,
        ], $overrides);
    }

    /** @test */
    public function student_registration_and_profile_update_store_birthday_and_country(): void
    {
        $this->post('/register', [
            'name' => 'Maya Cruz',
            'email' => 'maya@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'skill_level' => 'beginner',
            'is_first_time' => true,
        ])->assertRedirect(route('verification.notice'));

        $registered = User::where('email', 'maya@example.com')->with('studentProfile')->firstOrFail();
        $this->assertNull($registered->studentProfile->birthday);
        $this->assertNull($registered->studentProfile->country);

        $registered->markEmailAsVerified();

        // Store birthday & country via student onboarding
        $this->actingAs($registered)
            ->post(route('student.onboarding.store'), [
                'date_of_birth' => '2001-08-20',
                'country' => 'Philippines',
                'has_board' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('student.dashboard'));

        $registered->studentProfile->refresh();
        $this->assertSame('2001-08-20', $registered->studentProfile->birthday->format('Y-m-d'));
        $this->assertSame('Philippines', $registered->studentProfile->country);
        $this->assertSame(Carbon::parse('2001-08-20')->age, $registered->studentProfile->age);

        // Update birthday & country via profile settings
        $this->actingAs($this->student)
            ->patch(route('profile.update'), [
                'name' => 'Alex Rivera',
                'email' => 'alex@example.com',
                'birthday' => '1998-03-10',
                'country' => 'Canada',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->student->studentProfile->refresh();
        $this->assertSame('1998-03-10', $this->student->studentProfile->birthday->format('Y-m-d'));
        $this->assertSame('Canada', $this->student->studentProfile->country);
        $this->assertSame(Carbon::parse('1998-03-10')->age, $this->student->studentProfile->age);
    }

    /** @test */
    public function selecting_hotel_pickup_requires_a_hotel_name_before_waiver(): void
    {
        $this->actingAs($this->student)
            ->post(
                route('bookings.store-details', $this->instructor->id),
                $this->baseBookingPayload([
                    'needs_hotel_pickup' => true,
                    'hotel_name' => '',
                ])
            )
            ->assertSessionHasErrors(['hotel_name']);

        $this->assertDatabaseCount('bookings', 0);
    }

    /** @test */
    public function waiver_prefills_profile_and_hides_hotel_when_pickup_is_not_chosen(): void
    {
        $this->actingAs($this->student)
            ->post(
                route('bookings.store-details', $this->instructor->id),
                $this->baseBookingPayload([
                    'needs_hotel_pickup' => false,
                    'hotel_name' => 'Should Be Ignored Resort',
                ])
            )
            ->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();
        $this->assertFalse($booking->needs_hotel_pickup);
        $this->assertNull($booking->hotel_name);

        $expectedAge = Carbon::parse('1999-05-15')->age;

        $this->actingAs($this->student)
            ->get(route('bookings.waiver', $booking))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Booking/Waiver')
                ->where('waiverPrefill.name', 'Alex Rivera')
                ->where('waiverPrefill.birthday', '1999-05-15')
                ->where('waiverPrefill.birthday_formatted', 'May 15, 1999')
                ->where('waiverPrefill.age', $expectedAge)
                ->where('waiverPrefill.email', 'alex@example.com')
                ->where('waiverPrefill.country', 'Australia')
                ->where('waiverPrefill.needs_hotel_pickup', false)
                ->where('waiverPrefill.hotel_name', null)
                ->where('waiverPrefill.instructor_name', 'Coach Kai Santos')
                ->where('waiverPrefill.witness_name', 'Coach Kai Santos')
                ->where('waiverPrefill.date', now()->format('F d, Y'))
                ->where('waiverDocument.title', 'SURF LESSON WAIVER, LIABILITY, AND INDEMNITY AGREEMENT')
                ->has('waiverDocument.clauses', 7)
            );
    }

    /** @test */
    public function waiver_shows_hotel_when_pickup_is_chosen_and_saves_full_sisa_waiver_snapshot(): void
    {
        $this->actingAs($this->student)
            ->post(
                route('bookings.store-details', $this->instructor->id),
                $this->baseBookingPayload([
                    'needs_hotel_pickup' => true,
                    'hotel_name' => 'Bravo Beach Resort',
                ])
            )
            ->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();
        $this->assertTrue($booking->needs_hotel_pickup);
        $this->assertSame('Bravo Beach Resort', $booking->hotel_name);

        $this->actingAs($this->student)
            ->get(route('bookings.waiver', $booking))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Booking/Waiver')
                ->where('waiverPrefill.needs_hotel_pickup', true)
                ->where('waiverPrefill.hotel_name', 'Bravo Beach Resort')
            );

        // Missing manual emergency contact & printed full name must fail validation
        $this->actingAs($this->student)
            ->post(route('bookings.store-waiver', $booking), [
                'emergency_contact' => '',
                'signed_by' => '',
                'signature' => '',
                'agreement' => true,
            ])
            ->assertSessionHasErrors(['emergency_contact', 'signed_by', 'signature']);

        // Submitting complete manual entries succeeds and stores snapshot + PDF
        $this->actingAs($this->student)
            ->post(route('bookings.store-waiver', $booking), [
                'emergency_contact' => 'Elena Rivera - +61 412 345 678',
                'medical_info' => 'Mild bee sting allergy, carries EpiPen',
                'signed_by' => 'Alex Rivera',
                'signature' => $this->validPngDataUri,
                'agreement' => true,
                'participant_id' => $booking->primaryParticipant->id,
            ])
            ->assertRedirect(route('bookings.payment', $booking));

        $waiver = Waiver::where('booking_id', $booking->id)->firstOrFail();

        $this->assertSame('Alex Rivera', $waiver->participant_name);
        $this->assertSame('1999-05-15', $waiver->birthday->format('Y-m-d'));
        $this->assertSame(Carbon::parse('1999-05-15')->age, $waiver->age);
        $this->assertSame('alex@example.com', $waiver->email);
        $this->assertSame('Australia', $waiver->country);
        $this->assertSame('Bravo Beach Resort', $waiver->hotel_name);
        $this->assertSame('Elena Rivera - +61 412 345 678', $waiver->emergency_contact);
        $this->assertSame('Mild bee sting allergy, carries EpiPen', $waiver->medical_info);
        $this->assertSame('Coach Kai Santos', $waiver->instructor_name);
        $this->assertSame('Coach Kai Santos', $waiver->witness_name);
        $this->assertSame('Alex Rivera', $waiver->signed_by);
        $this->assertSame($this->validPngDataUri, $waiver->signature_data);

        Storage::disk('local')->assertExists($waiver->pdf_path);

        // Synced emergency contact & medical info on participant for instructor view
        $participant = $booking->primaryParticipant->fresh();
        $this->assertSame('Elena Rivera', $participant->emergency_contact_name);
        $this->assertSame('+61 412 345 678', $participant->emergency_contact_phone);
        $this->assertSame('Mild bee sting allergy, carries EpiPen', $participant->medical_info);

        // Blade PDF template renders Hotel ONLY when needs_hotel_pickup is true
        $htmlWithHotel = view('pdfs.waiver', [
            'booking' => $booking,
            'waiver_title' => config('waiver.title'),
            'clauses' => config('waiver.clauses'),
            'logo_data_uri' => null,
            'participant_name' => $waiver->participant_name,
            'birthday_formatted' => 'May 15, 1999',
            'age' => $waiver->age,
            'email' => $waiver->email,
            'country' => $waiver->country,
            'needs_hotel_pickup' => true,
            'hotel_name' => 'Bravo Beach Resort',
            'emergency_contact' => $waiver->emergency_contact,
            'medical_info' => $waiver->medical_info,
            'surf_shop' => 'SiaSurf',
            'instructor_name' => 'Coach Kai Santos',
            'witness_name' => 'Coach Kai Santos',
            'signed_by' => 'Alex Rivera',
            'signature_image' => $this->validPngDataUri,
            'signed_at' => now()->format('F d, Y'),
            'signed_at_datetime' => now()->format('F d, Y h:i A'),
            'retention_until' => now()->addYears(7)->format('F d, Y'),
            'waiver_id' => 'WVR-000001',
        ])->render();

        $this->assertStringContainsString('Hotel:', $htmlWithHotel);
        $this->assertStringContainsString('Bravo Beach Resort', $htmlWithHotel);
        $this->assertStringContainsString('Medical Info / Allergies or Conditions:', $htmlWithHotel);
        $this->assertStringContainsString('Mild bee sting allergy, carries EpiPen', $htmlWithHotel);
        $this->assertLessThan(
            strpos($htmlWithHotel, 'RISKS AND DANGERS TO PERSON'),
            strpos($htmlWithHotel, 'Mild bee sting allergy, carries EpiPen')
        );
        $this->assertGreaterThan(
            strpos($htmlWithHotel, 'PERSONAL HEALTH:'),
            strpos($htmlWithHotel, 'Mild bee sting allergy, carries EpiPen')
        );
        $this->assertStringContainsString('Coach Kai Santos', $htmlWithHotel);
        $this->assertStringContainsString('In the presence of', $htmlWithHotel);
        $this->assertStringContainsString('(Receptionist)/Staff', $htmlWithHotel);
        $this->assertStringContainsString('<img src="' . $this->validPngDataUri . '"', $htmlWithHotel);

        $htmlWithoutHotel = view('pdfs.waiver', [
            'booking' => $booking,
            'waiver_title' => config('waiver.title'),
            'clauses' => config('waiver.clauses'),
            'logo_data_uri' => null,
            'participant_name' => $waiver->participant_name,
            'birthday_formatted' => 'May 15, 1999',
            'age' => $waiver->age,
            'email' => $waiver->email,
            'country' => $waiver->country,
            'needs_hotel_pickup' => false,
            'hotel_name' => null,
            'emergency_contact' => $waiver->emergency_contact,
            'medical_info' => null,
            'surf_shop' => 'SiaSurf',
            'instructor_name' => 'Coach Kai Santos',
            'witness_name' => 'Coach Kai Santos',
            'signed_by' => 'Alex Rivera',
            'signature_image' => $this->validPngDataUri,
            'signed_at' => now()->format('F d, Y'),
            'signed_at_datetime' => now()->format('F d, Y h:i A'),
            'retention_until' => now()->addYears(7)->format('F d, Y'),
            'waiver_id' => 'WVR-000001',
        ])->render();

        $this->assertStringNotContainsString('Hotel:', $htmlWithoutHotel);
        $this->assertStringContainsString('None declared', $htmlWithoutHotel);
    }

    /** @test */
    public function student_can_download_a4_waiver_pdf_preview_from_waiver_window(): void
    {
        $this->actingAs($this->student)
            ->post(
                route('bookings.store-details', $this->instructor->id),
                $this->baseBookingPayload([
                    'needs_hotel_pickup' => false,
                ])
            )
            ->assertRedirect();

        $booking = Booking::latest('id')->firstOrFail();

        $response = $this->actingAs($this->student)
            ->post(route('bookings.waiver.download-pdf', $booking), [
                'emergency_contact' => 'Elena Rivera - +61 412 345 678',
                'medical_info' => 'None',
                'signed_by' => 'Alex Rivera',
                'signature' => $this->validPngDataUri,
            ]);

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('Content-Disposition'));

        $pdfBytes = $response->getContent();
        $this->assertIsString($pdfBytes);
        $this->assertStringStartsWith('%PDF-', $pdfBytes);
        $this->assertSame(1, preg_match('/\/Count\s+1\b/', $pdfBytes));

        $otherStudent = User::factory()->create(['role' => 'student']);
        $otherStudent->assignRole('student');

        $this->actingAs($otherStudent)
            ->post(route('bookings.waiver.download-pdf', $booking), [])
            ->assertForbidden();
    }
}
