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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $instructor;
    private SurfSpot $spot;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', 'instructor'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->student = User::factory()->student()->create();
        $this->student->assignRole('student');

        $this->instructor = User::factory()->instructor()->create();
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 1000,
        ]);

        $this->spot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
    }

    private function booking(array $attributes = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'date' => now()->addDay()->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'duration_hours' => 1,
            'student_age' => 25,
            'student_count' => 1,
            'status' => BookingStatus::Pending,
        ], $attributes));
    }

    private function signLiability(Booking $booking): void
    {
        Waiver::create([
            'booking_id' => $booking->id,
            'type' => 'liability',
            'signed_by' => $this->student->name,
            'signature_data' => 'data:image/png;base64,AAAA',
            'pdf_path' => 'waivers/test.pdf',
            'signed_at' => now(),
            'retention_until' => now()->addYears(7),
        ]);
    }

    private function payment(Booking $booking, array $attributes = []): Payment
    {
        return Payment::create(array_merge([
            'booking_id' => $booking->id,
            'amount' => $booking->total_amount,
            'payment_method' => PaymentMethod::GCash,
            'transaction_id' => 'mock_existing',
            'checkout_url' => 'http://localhost:8000/mock-checkout/mock_existing',
            'status' => PaymentStatus::Pending,
        ], $attributes));
    }

    public function test_posting_payment_method_while_awaiting_payment_redirects_to_payment_page(): void
    {
        $booking = $this->booking([
            'status' => BookingStatus::AwaitingPayment,
            'payment_deadline_at' => now()->addMinutes(10),
        ]);
        $this->payment($booking);
        $this->signLiability($booking);

        $response = $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking), ['payment_method' => 'gcash']);

        $response->assertRedirect(route('payments.show', $booking));
        $this->assertSame(BookingStatus::AwaitingPayment, $booking->fresh()->status);
    }

    public function test_confirmed_booking_cannot_be_reset_by_posting_payment_method(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);
        $this->payment($booking, ['status' => PaymentStatus::Completed, 'paid_at' => now()]);
        $this->signLiability($booking);

        $response = $this->actingAs($this->student)
            ->post(route('bookings.store-payment', $booking), ['payment_method' => 'cash']);

        $response->assertRedirect(route('student.bookings.show', $booking));
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(PaymentStatus::Completed, $booking->fresh()->payment->status);
    }

    public function test_payment_step_redirects_an_accepted_booking_to_the_gcash_page(): void
    {
        $booking = $this->booking([
            'status' => BookingStatus::AwaitingPayment,
            'payment_deadline_at' => now()->addMinutes(10),
        ]);
        $this->payment($booking);
        $this->signLiability($booking);

        $this->actingAs($this->student)
            ->get(route('bookings.payment', $booking))
            ->assertRedirect(route('payments.show', $booking));
    }

    public function test_gcash_reuses_the_existing_checkout_session(): void
    {
        $booking = $this->booking([
            'status' => BookingStatus::AwaitingPayment,
            'payment_deadline_at' => now()->addMinutes(10),
        ]);
        $payment = $this->payment($booking);
        $this->signLiability($booking);

        $this->actingAs($this->student)
            ->post(route('payments.gcash', $booking))
            ->assertRedirect($payment->checkout_url);

        $this->assertSame(1, Payment::where('booking_id', $booking->id)->count());
        $this->assertSame('mock_existing', $payment->fresh()->transaction_id);
    }

    public function test_gcash_is_blocked_until_the_instructor_accepts(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Pending]);
        $this->payment($booking);
        $this->signLiability($booking);

        $this->actingAs($this->student)
            ->post(route('payments.gcash', $booking))
            ->assertSessionHas('error');

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_mock_checkout_process_keeps_the_student_authenticated(): void
    {
        $booking = $this->booking([
            'status' => BookingStatus::AwaitingPayment,
            'payment_deadline_at' => now()->addMinutes(10),
        ]);
        $payment = $this->payment($booking);
        $this->signLiability($booking);

        // Use the real login endpoint so the test follows the same session
        // lifecycle as a browser, rather than bypassing it with actingAs().
        $this->post('/login', [
            'email' => $this->student->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($this->student);

        $this->get(route('payments.mock-checkout', $payment->transaction_id))
            ->assertOk();

        $this->post(route('payments.mock-process', $payment->transaction_id), [
            'success' => true,
        ])->assertRedirect(route('bookings.confirmation', $booking));

        $this->get(route('bookings.confirmation', $booking))->assertOk();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(PaymentStatus::Completed, $booking->fresh()->payment->status);
    }

    public function test_guest_cannot_open_or_process_a_mock_checkout(): void
    {
        $booking = $this->booking([
            'status' => BookingStatus::AwaitingPayment,
            'payment_deadline_at' => now()->addMinutes(10),
        ]);
        $payment = $this->payment($booking);

        $this->get(route('payments.mock-checkout', $payment->transaction_id))
            ->assertRedirect(route('login'));

        $this->post(route('payments.mock-process', $payment->transaction_id), [
            'success' => true,
        ])->assertRedirect(route('login'));

        $this->assertSame(BookingStatus::AwaitingPayment, $booking->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_another_student_cannot_process_someone_elses_mock_checkout(): void
    {
        $booking = $this->booking([
            'status' => BookingStatus::AwaitingPayment,
            'payment_deadline_at' => now()->addMinutes(10),
        ]);
        $payment = $this->payment($booking);

        $otherStudent = User::factory()->student()->create();
        $otherStudent->assignRole('student');

        $this->actingAs($otherStudent)
            ->get(route('payments.mock-checkout', $payment->transaction_id))
            ->assertForbidden();

        $this->actingAs($otherStudent)
            ->post(route('payments.mock-process', $payment->transaction_id), [
                'success' => true,
            ])
            ->assertForbidden();

        $this->assertSame(BookingStatus::AwaitingPayment, $booking->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_expired_gcash_hold_marks_the_booking_as_payment_timeout(): void
    {
        $booking = $this->booking([
            'status' => BookingStatus::AwaitingPayment,
            'payment_deadline_at' => now()->subMinute(),
        ]);
        $this->payment($booking);
        $this->signLiability($booking);

        $this->actingAs($this->student)
            ->post(route('payments.gcash', $booking))
            ->assertRedirect(route('student.bookings.show', $booking))
            ->assertSessionHas('error');

        $this->assertSame(BookingStatus::PaymentTimeout, $booking->fresh()->status);
    }

}

