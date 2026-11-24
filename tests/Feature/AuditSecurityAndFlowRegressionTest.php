<?php

namespace Tests\Feature;

use App\Contracts\PaymentServiceInterface;
use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\InstructorLevel;
use App\Enums\InstructorStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
use App\Enums\SuspensionStatus;
use App\Enums\SuspensionType;
use App\Exceptions\BookingChangeException;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\InstructorAppeal;
use App\Models\InstructorSuspension;
use App\Models\Payment;
use App\Models\SurfSpot;
use App\Models\User;
use App\Services\AppealService;
use App\Services\AvailabilityService;
use App\Services\BookingChangeService;
use App\Services\PayMongoPaymentService;
use App\Services\SuspensionService;
use App\Services\XenditPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditSecurityAndFlowRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $instructor;
    private User $admin;
    private SurfSpot $spot;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', 'instructor', 'admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->student = User::factory()->student()->create([
            'email_verified_at' => now(),
        ]);
        $this->student->assignRole('student');
        $this->student->studentProfile()->create([
            'skill_level' => SkillLevel::Beginner->value,
            'is_first_time' => true,
            'status' => 'active',
            'birthday' => now()->subYears(24)->format('Y-m-d'),
            'country' => 'Philippines',
        ]);

        $this->instructor = User::factory()->instructor()->create([
            'email' => 'private-instructor@example.test',
            'phone' => '+63 917 555 0101',
            'email_verified_at' => now(),
        ]);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => InstructorStatus::Active,
            'rate_per_hour' => 800,
            'guiding_rate' => 1100,
            'auto_accept_bookings' => false,
        ]);

        $this->admin = User::factory()->admin()->create([
            'email_verified_at' => now(),
        ]);
        $this->admin->assignRole('admin');

        $this->spot = SurfSpot::factory()->create([
            'difficulty' => 'beginner',
            'is_active' => true,
            'is_safe_zone' => true,
        ]);
    }

    public function test_xendit_webhook_rejects_wrong_token_and_ignores_terminal_booking(): void
    {
        config(['services.xendit.webhook_token' => 'top-secret-xendit-token']);
        app()->instance(PaymentServiceInterface::class, new XenditPaymentService());

        $booking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'status' => BookingStatus::Cancelled,
            'payment_status' => PaymentStatus::Failed,
        ]);

        Payment::create([
            'booking_id' => $booking->id,
            'amount' => $booking->total_amount,
            'payment_method' => PaymentMethod::GCash,
            'status' => PaymentStatus::Failed,
            'transaction_id' => 'ewc_test_terminal',
        ]);

        $payload = [
            'event' => 'ewallet.capture',
            'data' => [
                'id' => 'ewc_test_terminal',
                'status' => 'SUCCEEDED',
                'metadata' => ['booking_id' => (string) $booking->id],
            ],
        ];

        $this->withHeaders(['x-callback-token' => 'wrong-token'])
            ->postJson(route('payments.webhook'), $payload)
            ->assertStatus(401);

        $this->withHeaders(['x-callback-token' => 'top-secret-xendit-token'])
            ->postJson(route('payments.webhook'), $payload)
            ->assertStatus(200);

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(PaymentStatus::Failed, $booking->payment->fresh()->status);
    }

    public function test_paymongo_webhook_rejects_stale_replay_and_test_signature_in_live_mode(): void
    {
        config([
            'services.paymongo.webhook_secret' => 'whsec_test_secret',
            'services.paymongo.mode' => 'live',
        ]);

        $service = new PayMongoPaymentService();
        $payload = json_encode(['data' => ['id' => 'evt_1']]);

        $staleTimestamp = now()->subMinutes(10)->timestamp;
        $staleSig = hash_hmac('sha256', "{$staleTimestamp}.{$payload}", 'whsec_test_secret');
        $this->assertFalse($service->verifySignature("t={$staleTimestamp},li={$staleSig}", $payload));

        $freshTimestamp = now()->timestamp;
        $sig = hash_hmac('sha256', "{$freshTimestamp}.{$payload}", 'whsec_test_secret');
        // In live mode, `te=` (test mode signature) must NOT be accepted.
        $this->assertFalse($service->verifySignature("t={$freshTimestamp},te={$sig}", $payload));
        $this->assertTrue($service->verifySignature("t={$freshTimestamp},li={$sig}", $payload));
    }

    public function test_group_booking_requires_participants_and_waiver_enforces_rule_4_from_birthday(): void
    {
        $day = now()->addDays(4);
        Availability::create([
            'user_id' => $this->instructor->id,
            'day_of_week' => (int) $day->format('w'),
            'time_period' => 'morning',
            'is_available' => true,
            'start_time' => '06:00',
            'end_time' => '12:00',
            'slot_duration_minutes' => 60,
        ]);

        // Multi-student bookings (> 1 seat in one account) must fail validation.
        $this->actingAs($this->student)
            ->post(route('bookings.store-details', $this->instructor->id), [
                'date' => $day->format('Y-m-d'),
                'start_time' => '07:00',
                'end_time' => '09:00',
                'student_age' => 24,
                'height' => 170,
                'weight' => 65,
                'student_count' => 2,
                'has_board' => true,
                'surf_spot_id' => $this->spot->id,
            ])
            ->assertSessionHasErrors('student_count');

        // Clear primary birthday to simulate legacy profile supplying a child birthday on waiver.
        $this->student->studentProfile->update(['birthday' => null, 'age' => null]);

        $booking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id, // Level 2 instructor cannot teach age 5-12
            'surf_spot_id' => $this->spot->id,
            'status' => BookingStatus::Reserved,
            'skill_level' => SkillLevel::Beginner,
            'session_type' => SessionType::Teaching,
            'student_age' => 25,
            'payment_deadline_at' => now()->addMinutes(15),
        ]);

        $childBirthday = now()->subYears(9)->format('Y-m-d');

        $this->actingAs($this->student)
            ->post(route('bookings.store-waiver', $booking->id), [
                'emergency_contact' => 'Parent 09170001111',
                'signed_by' => 'Parent Guard',
                'signature' => 'data:image/png;base64,' . base64_encode('sig'),
                'agreement' => '1',
                'birthday' => $childBirthday,
            ])
            ->assertRedirect(route('bookings.create', $this->instructor->id))
            ->assertSessionHas('error');

        $this->assertSame(BookingStatus::Expired, $booking->fresh()->status);
    }

    public function test_booking_change_approve_revalidates_slot_conflicts_under_lock(): void
    {
        $targetDate = now()->addDays(4)->format('Y-m-d');
        $dayOfWeek = (int) now()->addDays(4)->format('w');

        Availability::create([
            'user_id' => $this->instructor->id,
            'day_of_week' => $dayOfWeek,
            'time_period' => 'morning',
            'is_available' => true,
            'start_time' => '06:00',
            'end_time' => '12:00',
            'slot_duration_minutes' => 60,
        ]);

        $originalBooking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'status' => BookingStatus::Confirmed,
            'skill_level' => SkillLevel::Beginner,
            'session_type' => SessionType::Teaching,
            'date' => now()->addDays(2)->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '07:00',
            'end_time' => '09:00',
            'duration_hours' => 2,
        ]);

        $changeRequest = BookingChangeRequest::create([
            'booking_id' => $originalBooking->id,
            'requested_by' => $this->instructor->id,
            'type' => ChangeRequestType::Reschedule,
            'status' => ChangeRequestStatus::Pending,
            'requires_admin_approval' => true,
            'reason' => 'Rescheduling to calmer morning window.',
            'original_date' => $originalBooking->date,
            'original_time_period' => 'morning',
            'original_start_time' => '07:00',
            'original_end_time' => '09:00',
            'new_date' => $targetDate,
            'new_time_period' => 'morning',
            'new_start_time' => '08:00',
            'new_end_time' => '10:00',
        ]);

        // Another student books the target slot while admin review was pending.
        $otherStudent = User::factory()->student()->create();
        Booking::factory()->create([
            'student_id' => $otherStudent->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'status' => BookingStatus::Confirmed,
            'skill_level' => SkillLevel::Beginner,
            'session_type' => SessionType::Teaching,
            'date' => $targetDate,
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
        ]);

        $this->expectException(BookingChangeException::class);
        app(BookingChangeService::class)->approve($changeRequest, $this->admin);
    }

    public function test_absent_on_live_cash_session_transitions_to_no_show_and_records_cash(): void
    {
        Storage::fake('local');

        $booking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'status' => BookingStatus::InProgress,
            'payment_status' => PaymentStatus::PendingCash,
            'started_at' => now()->subMinutes(20),
            'date' => now()->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
        ]);

        Payment::create([
            'booking_id' => $booking->id,
            'amount' => $booking->total_amount,
            'lesson_price' => $booking->effectiveLessonPrice(),
            'service_fee_amount' => $booking->effectiveServiceFeeAmount(),
            'payment_method' => PaymentMethod::Cash,
            'status' => PaymentStatus::PendingCash,
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.attendance', $booking->id), [
                'attendance_status' => AttendanceStatus::Absent->value,
                'cash_received' => true,
            ])
            ->assertRedirect();

        $booking->refresh();
        $this->assertSame(BookingStatus::NoShow, $booking->status);
        $this->assertSame(CancellationReason::StudentNoShow, $booking->cancellation_reason);
        $this->assertSame(AttendanceStatus::Absent, $booking->attendance_status);
        $this->assertSame(PaymentStatus::Completed, $booking->payment_status);
        $this->assertSame(PaymentStatus::Completed, $booking->payment->fresh()->status);
    }

    public function test_stale_awaiting_payment_is_swept_unless_receipt_pending_verification(): void
    {
        $unpaidTimedOut = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'status' => BookingStatus::AwaitingPayment,
            'payment_status' => PaymentStatus::Pending,
            'payment_deadline_at' => now()->subMinutes(5),
        ]);

        Payment::create([
            'booking_id' => $unpaidTimedOut->id,
            'amount' => $unpaidTimedOut->total_amount,
            'payment_method' => PaymentMethod::GCash,
            'status' => PaymentStatus::Pending,
        ]);

        $withReceipt = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'status' => BookingStatus::AwaitingPayment,
            'payment_status' => PaymentStatus::PendingVerification,
            'payment_deadline_at' => now()->subMinutes(5),
        ]);

        Payment::create([
            'booking_id' => $withReceipt->id,
            'amount' => $withReceipt->total_amount,
            'payment_method' => PaymentMethod::GCash,
            'status' => PaymentStatus::PendingVerification,
            'receipt_path' => 'receipts/proof.jpg',
        ]);

        $releasedCount = app(AvailabilityService::class)->releaseExpiredCheckoutHolds($this->instructor->id);

        $this->assertSame(1, $releasedCount);
        $this->assertSame(BookingStatus::PaymentTimeout, $unpaidTimedOut->fresh()->status);
        $this->assertSame(PaymentStatus::Failed, $unpaidTimedOut->payment->fresh()->status);
        $this->assertSame(BookingStatus::AwaitingPayment, $withReceipt->fresh()->status);

        // Reset and also verify the Artisan sweep command handles AwaitingPayment.
        $unpaidTimedOut->update(['status' => BookingStatus::AwaitingPayment, 'payment_deadline_at' => now()->subMinutes(5)]);
        $unpaidTimedOut->payment->update(['status' => PaymentStatus::Pending]);

        $this->artisan('bookings:expire-stale-requests')->assertExitCode(0);
        $this->assertSame(BookingStatus::PaymentTimeout, $unpaidTimedOut->fresh()->status);
    }

    public function test_banned_student_cannot_login_and_active_session_is_logged_out(): void
    {
        $this->student->studentProfile->update(['status' => 'banned']);

        $this->post(route('login'), [
            'email' => $this->student->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        $this->actingAs($this->student)
            ->get(route('student.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_active_booking_blocks_birthday_and_skill_assessment_mutations(): void
    {
        Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'status' => BookingStatus::Confirmed,
            'date' => now()->addDays(5)->format('Y-m-d'),
        ]);

        $this->actingAs($this->student)
            ->patch(route('profile.update'), [
                'name' => $this->student->name,
                'email' => $this->student->email,
                'birthday' => now()->subYears(8)->format('Y-m-d'),
            ])
            ->assertSessionHasErrors('birthday');

        $this->actingAs($this->student)
            ->post(route('student.onboarding.assessment'), [
                'can_swim_deep_water' => true,
                'is_first_time' => false,
                'answers' => [0 => 0],
                'confirmed' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_public_instructor_endpoints_do_not_leak_email_phone_or_role(): void
    {
        $this->instructor->certificates()->create([
            'type' => 'sisa',
            'file_path' => 'certificates/sisa.pdf',
            'status' => 'verified',
            'verified_at' => now(),
        ]);

        $this->get(route('instructors.search'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('instructors.data', 1, fn (Assert $item) => $item
                    ->missing('email')
                    ->missing('phone')
                    ->missing('role')
                    ->etc()
                )
            );

        $this->get(route('instructors.profile', $this->instructor->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('instructor', fn (Assert $item) => $item
                    ->missing('email')
                    ->missing('phone')
                    ->missing('role')
                    ->etc()
                )
            );
    }

    public function test_instructor_certificates_are_stored_on_private_local_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->actingAs($this->instructor)
            ->post(route('instructor.certificates.store'), [
                'certificates' => [UploadedFile::fake()->create('wasar_private.pdf', 200, 'application/pdf')],
            ])
            ->assertSessionHasNoErrors();

        $cert = $this->instructor->certificates()->latest('id')->firstOrFail();
        Storage::disk('local')->assertExists($cert->file_path);
        $this->assertSame([], Storage::disk('public')->allFiles('certificates'));
    }

    public function test_appeal_requires_active_suspension_and_admin_revoke_expires_pending_appeal(): void
    {
        $suspension = InstructorSuspension::create([
            'instructor_id' => $this->instructor->id,
            'type' => SuspensionType::Manual,
            'status' => SuspensionStatus::Active,
            'reason' => 'Safety violation under review.',
            'starts_at' => now(),
            'ends_at' => now()->addDays(15),
            'issued_by' => $this->admin->id,
        ]);

        $this->instructor->instructorProfile->update(['status' => InstructorStatus::Suspended]);

        $appeal = app(AppealService::class)->submit($this->instructor, $suspension, [
            'message' => 'I have addressed all equipment safety concerns.',
        ]);

        app(SuspensionService::class)->lift(
            $suspension,
            'Revoked after follow-up inspection.',
            $this->admin,
            SuspensionStatus::RevokedByAdmin,
            false
        );

        $this->assertSame('expired', $appeal->fresh()->status->value);

        $this->expectException(ValidationException::class);
        app(AppealService::class)->approve($appeal->fresh(), $this->admin, 'Too late');
    }
}
