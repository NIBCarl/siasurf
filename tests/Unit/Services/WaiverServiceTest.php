<?php

namespace Tests\Unit\Services;

use App\Enums\BookingStatus;
use App\Enums\SkillLevel;
use App\Enums\WaiverType;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use App\Services\WaiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WaiverServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WaiverService $waiverService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->waiverService = new WaiverService();
        Storage::fake('local');
    }

    /** @test */
    public function it_creates_liability_waiver_for_adult_student()
    {
        $student = User::factory()->create(['role' => 'student']);
        $instructor = User::factory()->create(['role' => 'instructor']);
        $surfSpot = SurfSpot::factory()->create();

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'surf_spot_id' => $surfSpot->id,
            'student_age' => 25,
            'skill_level' => SkillLevel::Beginner->value,
            'status' => BookingStatus::Pending->value,
        ]);

        $signatureData = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $waiver = $this->waiverService->createLiabilityWaiver($booking, 'John Doe', $signatureData);

        $this->assertInstanceOf(\App\Models\Waiver::class, $waiver);
        $this->assertEquals($booking->id, $waiver->booking_id);
        $this->assertEquals(WaiverType::Liability, $waiver->type);
        $this->assertEquals('John Doe', $waiver->signed_by);
        $this->assertNotNull($waiver->signed_at);
        $this->assertNotNull($waiver->retention_until);
        $this->assertTrue($waiver->retention_until->gt(now()));
        $this->assertNotNull($waiver->pdf_path);

        // Check PDF was created
        Storage::disk('local')->assertExists($waiver->pdf_path);
    }

    /** @test */
    public function it_creates_parental_consent_for_minor()
    {
        $student = User::factory()->create(['role' => 'student']);
        $instructor = User::factory()->create(['role' => 'instructor']);
        $surfSpot = SurfSpot::factory()->create();

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'surf_spot_id' => $surfSpot->id,
            'student_age' => 12,
            'skill_level' => SkillLevel::Beginner->value,
            'status' => BookingStatus::Pending->value,
        ]);

        $signatureData = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $waiver = $this->waiverService->createParentalConsent(
            $booking,
            'Jane Doe',
            $signatureData,
            'Emergency Contact',
            '+63 912 345 6789',
            'No known allergies',
            null,
            'Mother',
            'jane.doe@example.com'
        );

        $this->assertInstanceOf(\App\Models\Waiver::class, $waiver);
        $this->assertEquals($booking->id, $waiver->booking_id);
        $this->assertEquals(WaiverType::ParentalConsent, $waiver->type);
        $this->assertEquals('Jane Doe', $waiver->signed_by);
        $this->assertEquals('Mother', $waiver->guardian_relationship);
        $this->assertEquals('jane.doe@example.com', $waiver->guardian_email);
        $this->assertEquals($student->name, $waiver->participant_name);
        $this->assertEquals(12, $waiver->age);
        $this->assertTrue($waiver->signed_by_guardian);
        $this->assertNotNull($waiver->pdf_path);

        // Check PDF was created
        Storage::disk('local')->assertExists($waiver->pdf_path);

        // Verify Blade template embeds guardian signature image & minor participant info
        $html = view('pdfs.parental_consent', [
            'booking' => $booking,
            'minor_name' => $student->name,
            'minor_age' => 12,
            'parent_name' => 'Jane Doe',
            'guardian_relationship' => 'Mother',
            'guardian_email' => 'jane.doe@example.com',
            'signature_image' => $signatureData,
            'emergency_contact' => 'Emergency Contact',
            'emergency_phone' => '+63 912 345 6789',
            'medical_info' => 'No known allergies',
            'signed_at' => now()->format('F d, Y'),
            'signed_at_datetime' => now()->format('F d, Y h:i A'),
            'company_name' => 'SiaSurf',
            'logo_data_uri' => null,
            'waiver_id' => 'WVR-PC-000001',
        ])->render();

        $this->assertStringContainsString('<img src="' . $signatureData . '"', $html);
        $this->assertStringContainsString('Mother', $html);
        $this->assertStringContainsString('jane.doe@example.com', $html);
        $this->assertStringContainsString($student->name, $html);
    }

    /** @test */
    public function it_detects_when_all_waivers_are_signed_for_adult()
    {
        $student = User::factory()->create(['role' => 'student']);
        $instructor = User::factory()->create(['role' => 'instructor']);
        $surfSpot = SurfSpot::factory()->create();

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'surf_spot_id' => $surfSpot->id,
            'student_age' => 25,
        ]);

        // Initially no waivers
        $this->assertFalse($this->waiverService->hasRequiredWaivers($booking));

        // Add liability waiver
        $this->waiverService->createLiabilityWaiver(
            $booking,
            'John Doe',
            'data:image/png;base64,test'
        );

        $booking->refresh();
        $this->assertTrue($this->waiverService->hasRequiredWaivers($booking));
    }

    /** @test */
    public function it_detects_missing_parental_consent_for_minor()
    {
        $student = User::factory()->create(['role' => 'student']);
        $instructor = User::factory()->create(['role' => 'instructor']);
        $surfSpot = SurfSpot::factory()->create();

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'surf_spot_id' => $surfSpot->id,
            'student_age' => 12,
        ]);

        // Add liability waiver only
        $this->waiverService->createLiabilityWaiver(
            $booking,
            'Student Name',
            'data:image/png;base64,test'
        );

        $booking->refresh();

        // Should still be missing parental consent
        $this->assertFalse($this->waiverService->hasRequiredWaivers($booking));

        $missingTypes = $this->waiverService->getMissingWaiverTypes($booking);
        $this->assertContains('parental_consent', $missingTypes);
    }

    /** @test */
    public function it_returns_all_signed_when_minor_has_both_waivers()
    {
        $student = User::factory()->create(['role' => 'student']);
        $instructor = User::factory()->create(['role' => 'instructor']);
        $surfSpot = SurfSpot::factory()->create();

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'surf_spot_id' => $surfSpot->id,
            'student_age' => 12,
        ]);

        // Add both waivers
        $this->waiverService->createLiabilityWaiver(
            $booking,
            'Student Name',
            'data:image/png;base64,test'
        );

        $this->waiverService->createParentalConsent(
            $booking,
            'Parent Name',
            'data:image/png;base64,test',
            'Emergency Contact',
            '+63 912 345 6789',
            null
        );

        $booking->refresh();

        $this->assertTrue($this->waiverService->hasRequiredWaivers($booking));
        $this->assertEmpty($this->waiverService->getMissingWaiverTypes($booking));
    }

    /** @test */
    public function it_identifies_missing_liability_waiver()
    {
        $student = User::factory()->create(['role' => 'student']);
        $instructor = User::factory()->create(['role' => 'instructor']);
        $surfSpot = SurfSpot::factory()->create();

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'surf_spot_id' => $surfSpot->id,
            'student_age' => 25,
        ]);

        $missingTypes = $this->waiverService->getMissingWaiverTypes($booking);

        $this->assertContains('liability', $missingTypes);
        $this->assertNotContains('parental_consent', $missingTypes);
    }

    /** @test */
    public function it_generates_correct_retention_date()
    {
        $student = User::factory()->create(['role' => 'student']);
        $instructor = User::factory()->create(['role' => 'instructor']);
        $surfSpot = SurfSpot::factory()->create();

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'surf_spot_id' => $surfSpot->id,
        ]);

        $waiver = $this->waiverService->createLiabilityWaiver(
            $booking,
            'John Doe',
            'data:image/png;base64,test'
        );

        // Should be 7 years from now
        $expectedRetention = now()->copy()->addYears(7);
        $this->assertTrue($waiver->retention_until->diffInDays($expectedRetention) < 1);
    }

    /** @test */
    public function it_cleans_up_expired_waivers()
    {
        $student = User::factory()->create(['role' => 'student']);
        $instructor = User::factory()->create(['role' => 'instructor']);
        $surfSpot = SurfSpot::factory()->create();

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'surf_spot_id' => $surfSpot->id,
        ]);

        // Create an expired waiver
        $waiver = \App\Models\Waiver::factory()->create([
            'booking_id' => $booking->id,
            'type' => WaiverType::Liability->value,
            'retention_until' => now()->subDay(),
            'pdf_path' => 'waivers/expired_waiver.pdf',
        ]);

        // Create the file
        Storage::disk('local')->put('waivers/expired_waiver.pdf', 'test content');

        $deletedCount = $this->waiverService->cleanupExpiredWaivers();

        $this->assertEquals(1, $deletedCount);
        $this->assertDatabaseMissing('waivers', ['id' => $waiver->id]);
        Storage::disk('local')->assertMissing('waivers/expired_waiver.pdf');
    }
}
