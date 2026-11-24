<?php

namespace Tests\Feature;

use App\Contracts\PaymentServiceInterface;
use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\InstructorLevel;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\SurfSpot;
use App\Models\User;
use App\Models\Waiver;
use App\Notifications\PaymentReceivedNotification;
use App\Services\XenditPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentReceiptEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $instructor;
    private SurfSpot $spot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        $this->student = User::factory()->create([
            'name' => 'Maya Tourist',
            'email' => 'maya@example.com',
            'role' => 'student',
        ]);
        $this->student->assignRole('student');

        $this->instructor = User::factory()->create([
            'name' => 'Coach Junrey',
            'email' => 'junrey@example.com',
            'role' => 'instructor',
        ]);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 800,
        ]);

        $this->spot = SurfSpot::factory()->create([
            'name' => 'Cloud 9 (Inner)',
            'difficulty' => 'beginner',
        ]);
    }

    private function createBookingWithPayment(array $bookingAttrs = [], array $paymentAttrs = []): Booking
    {
        $booking = Booking::factory()->create(array_merge([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'date' => now()->addDays(2)->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
            'student_age' => 24,
            'student_count' => 1,
            'lesson_price' => 1600.00,
            'service_fee_percent' => 7.00,
            'service_fee_amount' => 112.00,
            'total_amount' => 1712.00,
            'status' => BookingStatus::AwaitingPayment,
            'payment_status' => PaymentStatus::Pending,
            'payment_deadline_at' => now()->addMinutes(15),
        ], $bookingAttrs));

        Payment::create(array_merge([
            'booking_id' => $booking->id,
            'amount' => $booking->total_amount,
            'lesson_price' => 1600.00,
            'service_fee_amount' => 112.00,
            'payment_method' => PaymentMethod::GCash,
            'transaction_id' => 'mock_tx_' . $booking->id,
            'checkout_url' => 'http://localhost/mock-checkout/mock_tx_' . $booking->id,
            'status' => PaymentStatus::Pending,
        ], $paymentAttrs));

        Waiver::create([
            'booking_id' => $booking->id,
            'type' => 'liability',
            'signed_by' => $this->student->name,
            'signature_data' => 'data:image/png;base64,AAAA',
            'pdf_path' => "waivers/test_{$booking->id}.pdf",
            'signed_at' => now(),
            'retention_until' => now()->addYears(7),
        ]);

        return $booking->fresh(['student', 'instructor', 'surfSpot', 'payment']);
    }

    public function test_notification_email_attaches_existing_pdf_receipt_with_role_aware_copy(): void
    {
        $booking = $this->createBookingWithPayment(
            ['status' => BookingStatus::Confirmed, 'payment_status' => PaymentStatus::Completed],
            ['status' => PaymentStatus::Completed, 'paid_at' => now()]
        );

        $notification = new PaymentReceivedNotification($booking->payment);

        $studentMail = $notification->toMail($this->student);
        $this->assertStringContainsString('Official Receipt & Payment Confirmed', $studentMail->subject);
        $this->assertCount(1, $studentMail->rawAttachments);
        $this->assertSame("SiaSurf_Receipt_{$booking->id}.pdf", $studentMail->rawAttachments[0]['name']);
        $this->assertSame('application/pdf', $studentMail->rawAttachments[0]['options']['mime']);
        $this->assertStringStartsWith('%PDF-', $studentMail->rawAttachments[0]['data']);

        $instructorMail = $notification->toMail($this->instructor);
        $this->assertStringContainsString('Lesson Payment Received & Receipt', $instructorMail->subject);
        $this->assertCount(1, $instructorMail->rawAttachments);
        $this->assertSame("SiaSurf_Receipt_{$booking->id}.pdf", $instructorMail->rawAttachments[0]['name']);
        $this->assertStringStartsWith('%PDF-', $instructorMail->rawAttachments[0]['data']);
    }

    public function test_mock_checkout_payment_sends_receipt_email_to_both_student_and_instructor(): void
    {
        Notification::fake();

        $booking = $this->createBookingWithPayment();

        $this->actingAs($this->student)
            ->post(route('payments.mock-process', $booking->payment->transaction_id), [
                'success' => true,
            ])
            ->assertRedirect(route('bookings.confirmation', $booking->id));

        Notification::assertSentToTimes($this->student, PaymentReceivedNotification::class, 1);
        Notification::assertSentToTimes($this->instructor, PaymentReceivedNotification::class, 1);

        Notification::assertSentTo($this->student, PaymentReceivedNotification::class, function (PaymentReceivedNotification $notification) use ($booking) {
            $mail = $notification->toMail($this->student);

            return count($mail->rawAttachments) === 1
                && $mail->rawAttachments[0]['name'] === "SiaSurf_Receipt_{$booking->id}.pdf";
        });

        Notification::assertSentTo($this->instructor, PaymentReceivedNotification::class, function (PaymentReceivedNotification $notification) use ($booking) {
            $mail = $notification->toMail($this->instructor);

            return count($mail->rawAttachments) === 1
                && $mail->rawAttachments[0]['name'] === "SiaSurf_Receipt_{$booking->id}.pdf";
        });
    }

    public function test_xendit_webhook_sends_receipt_once_to_student_and_instructor_and_deduplicates_retries(): void
    {
        Notification::fake();
        config(['services.xendit.webhook_token' => '']);

        $booking = $this->createBookingWithPayment([], [
            'transaction_id' => 'ewc_xendit_123',
        ]);

        $payload = [
            'event' => 'ewallet.capture',
            'data' => [
                'id' => 'ewc_xendit_123',
                'status' => 'SUCCEEDED',
                'reference_id' => "booking-{$booking->id}-1234-abcd",
                'metadata' => [
                    'booking_id' => (string) $booking->id,
                ],
            ],
        ];

        $this->app->bind(PaymentServiceInterface::class, fn () => new XenditPaymentService());

        $this->postJson(route('payments.webhook'), $payload)->assertOk();
        $this->postJson(route('payments.webhook'), $payload)->assertOk();

        Notification::assertSentToTimes($this->student, PaymentReceivedNotification::class, 1);
        Notification::assertSentToTimes($this->instructor, PaymentReceivedNotification::class, 1);
    }

    public function test_payment_success_redirect_fallback_sends_receipt_once_and_prevents_duplicate_on_refresh(): void
    {
        Notification::fake();

        $booking = $this->createBookingWithPayment([], [
            'transaction_id' => 'ewc_redirect_456',
        ]);

        $gatewayMock = $this->mock(PaymentServiceInterface::class);
        $gatewayMock->shouldReceive('getPaymentStatus')
            ->once()
            ->with('ewc_redirect_456')
            ->andReturn('succeeded');

        $this->actingAs($this->student)
            ->get(route('payments.success', $booking))
            ->assertRedirect(route('bookings.confirmation', $booking));

        // Second refresh sees completed payment and should not re-notify
        $this->actingAs($this->student)
            ->get(route('payments.success', $booking))
            ->assertRedirect(route('bookings.confirmation', $booking));

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(PaymentStatus::Completed, $booking->fresh()->payment_status);

        Notification::assertSentToTimes($this->student, PaymentReceivedNotification::class, 1);
        Notification::assertSentToTimes($this->instructor, PaymentReceivedNotification::class, 1);
    }

    public function test_manual_gcash_receipt_verification_sends_receipt_to_both_student_and_instructor(): void
    {
        Notification::fake();

        $booking = $this->createBookingWithPayment([], [
            'transaction_id' => null,
            'checkout_url' => null,
        ]);

        $this->actingAs($this->student)
            ->post(route('payments.receipt.upload', $booking), [
                'receipt' => UploadedFile::fake()->image('gcash.png'),
                'reference' => 'GCASH-998877',
            ])
            ->assertRedirect();

        $this->actingAs($this->instructor)
            ->post(route('payments.receipt.verify', $booking))
            ->assertRedirect();

        Notification::assertSentToTimes($this->student, PaymentReceivedNotification::class, 1);
        Notification::assertSentToTimes($this->instructor, PaymentReceivedNotification::class, 1);
    }

    public function test_cash_mark_as_paid_sends_receipt_only_when_paid_in_full(): void
    {
        Notification::fake();

        $fullCashBooking = $this->createBookingWithPayment(
            [
                'status' => BookingStatus::Confirmed,
                'payment_status' => PaymentStatus::PendingCash,
                'payment_deadline_at' => null,
            ],
            [
                'payment_method' => PaymentMethod::Cash,
                'transaction_id' => null,
                'checkout_url' => null,
                'status' => PaymentStatus::PendingCash,
            ]
        );

        $this->actingAs($this->instructor)
            ->post(route('payments.cash', $fullCashBooking), [
                'amount_received' => $fullCashBooking->total_amount,
            ])
            ->assertRedirect();

        Notification::assertSentToTimes($this->student, PaymentReceivedNotification::class, 1);
        Notification::assertSentToTimes($this->instructor, PaymentReceivedNotification::class, 1);

        // Underpaid cash should not email a completed receipt
        Notification::fake();

        $underpaidBooking = $this->createBookingWithPayment(
            [
                'status' => BookingStatus::Confirmed,
                'payment_status' => PaymentStatus::PendingCash,
                'payment_deadline_at' => null,
            ],
            [
                'payment_method' => PaymentMethod::Cash,
                'transaction_id' => null,
                'checkout_url' => null,
                'status' => PaymentStatus::PendingCash,
            ]
        );

        $this->actingAs($this->instructor)
            ->post(route('payments.cash', $underpaidBooking), [
                'amount_received' => 500,
                'notes' => 'Student only brought 500 PHP cash.',
            ])
            ->assertRedirect();

        Notification::assertNotSentTo($this->student, PaymentReceivedNotification::class);
        Notification::assertNotSentTo($this->instructor, PaymentReceivedNotification::class);
    }

    public function test_cash_settled_via_attendance_sends_receipt_to_both_student_and_instructor(): void
    {
        Notification::fake();

        $start = now()->subMinutes(15);

        $booking = $this->createBookingWithPayment(
            [
                'date' => $start->format('Y-m-d'),
                'start_time' => $start->format('H:i'),
                'end_time' => $start->copy()->addHours(2)->format('H:i'),
                'status' => BookingStatus::Confirmed,
                'payment_status' => PaymentStatus::PendingCash,
                'payment_deadline_at' => null,
            ],
            [
                'payment_method' => PaymentMethod::Cash,
                'transaction_id' => null,
                'checkout_url' => null,
                'status' => PaymentStatus::PendingCash,
            ]
        );

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.attendance', $booking), [
                'attendance_status' => AttendanceStatus::Present->value,
                'cash_received' => true,
            ])
            ->assertRedirect();

        Notification::assertSentToTimes($this->student, PaymentReceivedNotification::class, 1);
        Notification::assertSentToTimes($this->instructor, PaymentReceivedNotification::class, 1);
    }
}
