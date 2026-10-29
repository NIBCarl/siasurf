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
use App\Models\Waiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * GCash proof-of-payment: private storage, authorised viewing, and the
 * instructor/SISA approval that settles the payment.
 */
class GcashReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $instructor;
    private Booking $booking;
    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

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

        $spot = SurfSpot::factory()->create(['difficulty' => 'beginner']);

        $this->booking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $spot->id,
            'date' => now()->addDays(3)->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
            'student_age' => 25,
            'student_count' => 1,
            'status' => BookingStatus::AwaitingPayment,
            'payment_deadline_at' => now()->addMinutes(10),
        ]);

        $this->payment = Payment::create([
            'booking_id' => $this->booking->id,
            'amount' => $this->booking->total_amount,
            'payment_method' => PaymentMethod::GCash,
            'status' => PaymentStatus::Pending,
        ]);

        // BR-4.4 - no payment step before the liability waiver is signed.
        Waiver::create([
            'booking_id' => $this->booking->id,
            'type' => 'liability',
            'signed_by' => $this->student->name,
            'signature_data' => 'data:image/png;base64,AAAA',
            'pdf_path' => 'waivers/test.pdf',
            'signed_at' => now(),
            'retention_until' => now()->addYears(7),
        ]);
    }

    private function uploadReceipt(): void
    {
        $this->actingAs($this->student)
            ->post(route('payments.receipt.upload', $this->booking), [
                'receipt' => UploadedFile::fake()->image('gcash.png'),
                'reference' => 'REF-123456',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    /** @test */
    public function a_student_can_upload_a_gcash_receipt(): void
    {
        $this->uploadReceipt();

        $payment = $this->payment->fresh();

        $this->assertSame(PaymentStatus::PendingVerification, $payment->status);
        $this->assertSame('REF-123456', $payment->receipt_reference);
        $this->assertNotNull($payment->receipt_uploaded_at);
        $this->assertNotNull($payment->receipt_path);
        Storage::disk('local')->assertExists($payment->receipt_path);
    }

    /** @test */
    public function only_the_student_who_owns_the_booking_can_upload(): void
    {
        $other = User::factory()->create(['role' => 'student']);
        $other->assignRole('student');

        $this->actingAs($other)
            ->post(route('payments.receipt.upload', $this->booking), [
                'receipt' => UploadedFile::fake()->image('gcash.png'),
            ])
            ->assertForbidden();

        $this->assertNull($this->payment->fresh()->receipt_path);
    }

    /** @test */
    public function the_instructor_can_open_the_full_receipt_image(): void
    {
        $this->uploadReceipt();

        $this->actingAs($this->instructor)
            ->get(route('payments.receipt.image', $this->booking))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    /** @test */
    public function the_student_can_open_their_own_receipt_image(): void
    {
        $this->uploadReceipt();

        $this->actingAs($this->student)
            ->get(route('payments.receipt.image', $this->booking))
            ->assertOk();
    }

    /** @test */
    public function an_unrelated_user_cannot_open_the_receipt_image(): void
    {
        $this->uploadReceipt();

        $other = User::factory()->create(['role' => 'student']);
        $other->assignRole('student');

        $this->actingAs($other)
            ->get(route('payments.receipt.image', $this->booking))
            ->assertForbidden();
    }

    /** @test */
    public function the_instructor_can_verify_the_receipt_and_settle_the_payment(): void
    {
        $this->uploadReceipt();

        $this->actingAs($this->instructor)
            ->post(route('payments.receipt.verify', $this->booking))
            ->assertRedirect();

        $payment = $this->payment->fresh();
        $booking = $this->booking->fresh();

        $this->assertSame(PaymentStatus::Completed, $payment->status);
        $this->assertNotNull($payment->receipt_verified_at);
        $this->assertSame($this->instructor->id, $payment->receipt_verified_by);

        $this->assertSame(PaymentStatus::Completed, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
    }

    /** @test */
    public function a_student_cannot_verify_their_own_receipt(): void
    {
        $this->uploadReceipt();

        $this->actingAs($this->student)
            ->post(route('payments.receipt.verify', $this->booking))
            ->assertForbidden();

        $this->assertSame(PaymentStatus::PendingVerification, $this->payment->fresh()->status);
    }

    /** @test */
    public function rejecting_a_receipt_keeps_the_booking_open_for_a_new_upload(): void
    {
        $this->uploadReceipt();

        $this->actingAs($this->instructor)
            ->post(route('payments.receipt.reject', $this->booking), [
                'note' => 'The amount does not match the booking total.',
            ])
            ->assertRedirect();

        $payment = $this->payment->fresh();
        $booking = $this->booking->fresh();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('The amount does not match the booking total.', $payment->receipt_note);
        $this->assertSame(BookingStatus::AwaitingPayment, $booking->status);
        $this->assertTrue($booking->payment_deadline_at->isFuture());
    }

    /** @test */
    public function the_student_cannot_cancel_once_a_receipt_is_submitted(): void
    {
        $this->uploadReceipt();

        $booking = $this->booking->fresh(['payment']);

        $this->assertTrue($booking->hasSubmittedPayment());
        $this->assertFalse($booking->canBeCancelled());

        $this->actingAs($this->student)
            ->post(route('student.bookings.cancel', $booking), ['reason' => 'Changed my mind.'])
            ->assertForbidden();

        $this->assertSame(BookingStatus::AwaitingPayment, $booking->fresh()->status);
    }
}
