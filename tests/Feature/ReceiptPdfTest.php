<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\InstructorLevel;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\SurfSpot;
use App\Models\User;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReceiptPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $instructor;
    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        $this->student = User::factory()->create([
            'name' => 'Christian Student',
            'email' => 'student@example.com',
            'role' => 'student',
        ]);
        $this->student->assignRole('student');

        $this->instructor = User::factory()->create([
            'name' => 'Brent',
            'role' => 'instructor',
        ]);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 600,
        ]);

        $spot = SurfSpot::factory()->create([
            'name' => 'Burgos (Inner)',
            'difficulty' => 'beginner',
        ]);

        $this->booking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $spot->id,
            'date' => '2026-11-21',
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
            'skill_level' => 'beginner',
            'student_count' => 1,
            'total_amount' => 1200.00,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Completed,
        ]);

        Payment::create([
            'booking_id' => $this->booking->id,
            'amount' => 1200.00,
            'payment_method' => PaymentMethod::GCash,
            'transaction_id' => 'mock_7O3DihYglX8aZYdk',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);
    }

    #[Test]
    public function it_generates_a_single_page_gcash_style_pdf_receipt(): void
    {
        $service = app(ReceiptService::class);
        $path = $service->generateReceipt($this->booking);

        Storage::disk('local')->assertExists($path);

        $pdfBytes = Storage::disk('local')->get($path);
        $this->assertStringStartsWith('%PDF-', $pdfBytes);
        // Verify single-page PDF output (exactly one /Type /Page object, excluding /Pages)
        preg_match_all('/\/Type\s*\/Page\b/', $pdfBytes, $matches);
        $this->assertCount(1, $matches[0], 'Receipt PDF should fit on a single page.');
    }

    #[Test]
    public function it_renders_paid_gcash_and_underpaid_cash_receipt_views(): void
    {
        $this->booking->load(['student', 'instructor', 'surfSpot', 'payment']);

        $html = view('pdfs.receipt', [
            'booking' => $this->booking,
            'receipt_number' => 'RCP-000013',
            'date_issued' => 'November 20, 2026',
            'company_name' => 'SiaSurf',
            'company_address' => 'Siargao Island, Surigao del Norte, Philippines',
            'company_email' => 'admin@siasurf.com',
        ])->render();

        $this->assertStringContainsString('Payment received for', $html);
        $this->assertStringContainsString('SiaSurf', $html);
        $this->assertStringContainsString('PHP 1,200.00', $html);
        $this->assertStringContainsString('using your GCash', $html);
        // Mock transaction IDs should not leak onto the receipt; fall back to the receipt number.
        $this->assertStringContainsString('Ref. No. RCP-000013', $html);
        $this->assertStringNotContainsString('mock_7O3DihYglX8aZYdk', $html);
        $this->assertStringNotContainsString('student@example.com', $html);
        $this->assertStringNotContainsString('Lesson Total', $html);
        $this->assertStringContainsString('Details', $html);
        $this->assertStringContainsString('Nov 21, 2026', $html);
        $this->assertStringContainsString('1 Student', $html);

        // When a real GCash receipt reference is provided, show it as Ref. No. and keep Receipt No. in Details.
        $this->booking->payment->update([
            'receipt_reference' => '8581 4706 6',
        ]);
        $this->booking->refresh()->load(['student', 'instructor', 'surfSpot', 'payment']);

        $gcashRefHtml = view('pdfs.receipt', [
            'booking' => $this->booking,
            'receipt_number' => 'RCP-000013',
            'date_issued' => 'November 20, 2026',
            'company_name' => 'SiaSurf',
            'company_address' => 'Siargao Island, Surigao del Norte, Philippines',
            'company_email' => 'admin@siasurf.com',
        ])->render();

        $this->assertStringContainsString('Ref. No. 8581 4706 6', $gcashRefHtml);
        $this->assertStringContainsString('Receipt No.', $gcashRefHtml);

        $this->booking->payment->update([
            'amount' => 700.00,
            'payment_method' => PaymentMethod::Cash,
            'status' => PaymentStatus::Underpaid,
        ]);
        $this->booking->refresh()->load(['student', 'instructor', 'surfSpot', 'payment']);

        $underpaidHtml = view('pdfs.receipt', [
            'booking' => $this->booking,
            'receipt_number' => 'RCP-000013',
            'date_issued' => 'November 20, 2026',
            'company_name' => 'SiaSurf',
            'company_address' => 'Siargao Island, Surigao del Norte, Philippines',
            'company_email' => 'admin@siasurf.com',
        ])->render();

        $this->assertStringContainsString('PHP 700.00', $underpaidHtml);
        $this->assertStringContainsString('paid via Cash on Hand', $underpaidHtml);
        $this->assertStringContainsString('Lesson Total', $underpaidHtml);
        $this->assertStringContainsString('Balance Due', $underpaidHtml);
        $this->assertStringContainsString('PHP 500.00', $underpaidHtml);

        $path = app(ReceiptService::class)->generateReceipt($this->booking);
        $pdfBytes = Storage::disk('local')->get($path);
        preg_match_all('/\/Type\s*\/Page\b/', $pdfBytes, $matches);
        $this->assertCount(1, $matches[0], 'Underpaid receipt PDF should also fit on a single page.');
    }

    #[Test]
    public function student_can_download_their_receipt_pdf(): void
    {
        $response = $this->actingAs($this->student)
            ->get(route('payments.receipt', $this->booking));

        $response->assertOk();
        $this->assertStringContainsString(
            "SiaSurf_Receipt_{$this->booking->id}.pdf",
            (string) $response->headers->get('content-disposition')
        );
    }
}
