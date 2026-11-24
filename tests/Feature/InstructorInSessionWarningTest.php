<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\InstructorLevel;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SkillLevel;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Certificate;
use App\Models\Payment;
use App\Models\SurfSpot;
use App\Models\User;
use App\Models\Waiver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorInSessionWarningTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $otherStudent;
    private User $instructor;
    private SurfSpot $spot;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        $this->travelTo(Carbon::create(2026, 11, 25, 9, 30, 0, config('app.timezone', 'Asia/Manila')));

        $this->student = User::factory()->create([
            'name' => 'Tourist Booked',
            'role' => 'student',
        ]);
        $this->student->assignRole('student');
        $this->student->studentProfile()->create([
            'skill_level' => SkillLevel::Beginner->value,
            'is_first_time' => false,
            'status' => 'active',
        ]);

        $this->otherStudent = User::factory()->create([
            'name' => 'Secret Surfer Name',
            'role' => 'student',
        ]);
        $this->otherStudent->assignRole('student');

        $this->instructor = User::factory()->create([
            'name' => 'Coach Kiko',
            'role' => 'instructor',
        ]);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 800,
            'auto_accept_bookings' => false,
        ]);

        Certificate::create([
            'user_id' => $this->instructor->id,
            'type' => CertificateType::SISA,
            'file_path' => 'certificates/sisa.pdf',
            'status' => CertificateStatus::Verified,
            'verified_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        $this->spot = SurfSpot::factory()->create([
            'name' => 'Jacking Horse (Inner)',
            'difficulty' => 'beginner',
            'is_active' => true,
        ]);
    }

    public function test_shows_warning_across_booking_and_public_pages_when_instructor_is_in_progress_without_leaking_privacy(): void
    {
        $liveBooking = Booking::factory()->create([
            'id' => 8842,
            'student_id' => $this->otherStudent->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'date' => '2026-11-25',
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
            'status' => BookingStatus::InProgress,
            'started_at' => now()->subMinutes(45),
        ]);

        // Step 1: Booking/Create
        $this->actingAs($this->student)
            ->get(route('bookings.create', $this->instructor))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Booking/Create')
                ->where('instructorSessionWarning.in_session', true)
                ->where('instructorSessionWarning.ends_at_label', '10:00 AM')
                ->where('instructorSessionWarning.message', function (string $message) use ($liveBooking) {
                    $this->assertStringContainsString('Coach Kiko', $message);
                    $this->assertStringNotContainsString('Secret Surfer Name', $message);
                    $this->assertStringNotContainsString('8842', $message);
                    $this->assertStringNotContainsString('Jacking Horse', $message);

                    return true;
                })
            );

        // Public profile & QR scan pages
        $this->actingAs($this->student)
            ->get(route('instructors.profile', $this->instructor))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Instructors/Profile')
                ->where('instructorSessionWarning.in_session', true)
                ->where('instructorSessionWarning.ends_at_label', '10:00 AM')
            );

        $this->get(route('walkin.scan', $this->instructor))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/WalkIn/Scan')
                ->where('instructorSessionWarning.in_session', true)
            );

        // Step 3 (Payment) and Step 4 (Confirmation while Pending)
        $pendingBooking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'date' => '2026-11-26',
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
            'student_age' => 25,
            'status' => BookingStatus::Reserved,
            'payment_deadline_at' => now()->addMinutes(10),
        ]);

        Waiver::create([
            'booking_id' => $pendingBooking->id,
            'type' => 'liability',
            'signed_by' => $this->student->name,
            'signature_data' => 'data:image/png;base64,AAAA',
            'pdf_path' => 'waivers/test.pdf',
            'signed_at' => now(),
            'retention_until' => now()->addYears(7),
        ]);

        $this->actingAs($this->student)
            ->get(route('bookings.payment', $pendingBooking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Booking/Payment')
                ->where('instructorSessionWarning.in_session', true)
            );

        $pendingBooking->update([
            'status' => BookingStatus::Pending,
            'payment_deadline_at' => null,
            'accept_deadline_at' => now()->addHours(2),
        ]);

        $this->actingAs($this->student)
            ->get(route('bookings.confirmation', $pendingBooking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Booking/Confirmation')
                ->where('instructorSessionWarning.in_session', true)
            );

        // Once confirmed, confirmation page no longer shows pending-response warning
        $pendingBooking->update(['status' => BookingStatus::Confirmed]);

        $this->actingAs($this->student)
            ->get(route('bookings.confirmation', $pendingBooking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Booking/Confirmation')
                ->where('instructorSessionWarning', null)
            );
    }

    public function test_option_b_shows_warning_for_confirmed_booking_overlapping_now_and_clears_on_no_show_or_future(): void
    {
        // Confirmed lesson 09:00-11:00 while now is 09:30 (before attendance is tapped)
        $overlappingConfirmed = Booking::factory()->create([
            'student_id' => $this->otherStudent->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'date' => '2026-11-25',
            'time_period' => 'morning',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'duration_hours' => 2,
            'status' => BookingStatus::Confirmed,
        ]);

        $this->actingAs($this->student)
            ->get(route('bookings.create', $this->instructor))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('instructorSessionWarning.in_session', true)
                ->where('instructorSessionWarning.ends_at_label', '11:00 AM')
            );

        // Marking absent/no-show clears the warning immediately
        $overlappingConfirmed->update(['status' => BookingStatus::NoShow]);

        $this->actingAs($this->student)
            ->get(route('bookings.create', $this->instructor))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('instructorSessionWarning', null)
            );

        // Confirmed lesson later today (13:00-15:00) or earlier today (06:00-08:00) does not trigger warning
        Booking::factory()->create([
            'student_id' => $this->otherStudent->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'date' => '2026-11-25',
            'time_period' => 'afternoon',
            'start_time' => '13:00',
            'end_time' => '15:00',
            'duration_hours' => 2,
            'status' => BookingStatus::Confirmed,
        ]);

        Booking::factory()->create([
            'student_id' => $this->otherStudent->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'date' => '2026-11-25',
            'time_period' => 'morning',
            'start_time' => '06:00',
            'end_time' => '08:00',
            'duration_hours' => 2,
            'status' => BookingStatus::Confirmed,
        ]);

        $this->actingAs($this->student)
            ->get(route('bookings.create', $this->instructor))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('instructorSessionWarning', null)
            );
    }

    public function test_ignores_stale_in_progress_booking_from_yesterday(): void
    {
        Booking::factory()->create([
            'student_id' => $this->otherStudent->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'date' => '2026-11-24',
            'time_period' => 'afternoon',
            'start_time' => '14:00',
            'end_time' => '16:00',
            'duration_hours' => 2,
            'status' => BookingStatus::InProgress,
            'started_at' => now()->subDay(),
        ]);

        $this->actingAs($this->student)
            ->get(route('bookings.create', $this->instructor))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('instructorSessionWarning', null)
            );
    }

    public function test_suppresses_warning_when_instant_book_is_enabled(): void
    {
        $this->instructor->instructorProfile->update(['auto_accept_bookings' => true]);

        Booking::factory()->create([
            'student_id' => $this->otherStudent->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'date' => '2026-11-25',
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
            'status' => BookingStatus::InProgress,
            'started_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($this->student)
            ->get(route('bookings.create', $this->instructor))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('instructorSessionWarning', null)
            );
    }

    public function test_in_session_warning_is_non_blocking_and_allows_submitting_future_booking(): void
    {
        Booking::factory()->create([
            'student_id' => $this->otherStudent->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'date' => '2026-11-25',
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
            'status' => BookingStatus::InProgress,
            'started_at' => now()->subMinutes(30),
        ]);

        $futureDate = Carbon::create(2026, 11, 27, 8, 0, 0, config('app.timezone', 'Asia/Manila'));

        Availability::create([
            'user_id' => $this->instructor->id,
            'day_of_week' => (int) $futureDate->format('w'),
            'time_period' => 'morning',
            'start_time' => '06:00',
            'end_time' => '12:00',
            'is_available' => true,
        ]);

        $response = $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor), [
                'date' => $futureDate->format('Y-m-d'),
                'start_time' => '08:00',
                'end_time' => '10:00',
                'session_type' => 'teaching',
                'student_age' => 24,
                'surf_spot_id' => $this->spot->id,
                'has_board' => true,
            ]);

        $booking = Booking::where('student_id', $this->student->id)->latest('id')->first();
        $this->assertNotNull($booking);
        $response->assertRedirect(route('bookings.waiver', $booking->id));
    }
}
