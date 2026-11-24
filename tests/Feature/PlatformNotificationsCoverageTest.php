<?php

namespace Tests\Feature;

use App\Contracts\PaymentServiceInterface;
use App\Enums\BookingStatus;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\FineStatus;
use App\Enums\IncidentOutcome;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\InstructorLevel;
use App\Enums\InstructorStatus;
use App\Enums\LedgerSettlementStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
use App\Events\NotificationFeedUpdated;
use App\Models\Booking;
use App\Models\Certificate;
use App\Models\InstructorDailyLedger;
use App\Models\InstructorFine;
use App\Models\Payment;
use App\Models\Review;
use App\Models\SafetyIncident;
use App\Models\SkillUpgradeRequest;
use App\Models\SurfSpot;
use App\Models\User;
use App\Models\Waiver;
use App\Notifications\CashRemittanceOverdueNotification;
use App\Notifications\CashUnderpaymentFlaggedNotification;
use App\Notifications\CertificateStatusNotification;
use App\Notifications\CertificateSubmittedNotification;
use App\Notifications\FineSettledNotification;
use App\Notifications\GcashReceiptRejectedNotification;
use App\Notifications\GcashReceiptUploadedNotification;
use App\Notifications\InstructorPendingVerificationNotification;
use App\Notifications\NewUserRegisteredNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\ReviewReceivedNotification;
use App\Notifications\ReviewResponseNotification;
use App\Notifications\SkillUpgradeRequestedNotification;
use App\Notifications\SkillUpgradeResolvedNotification;
use App\Notifications\StrikeRevokedNotification;
use App\Notifications\StudentAccountStatusNotification;
use App\Services\FeedbackService;
use App\Services\MockPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PlatformNotificationsCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $instructor;

    private User $admin;

    private SurfSpot $spot;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');

        $this->student = User::factory()->student()->create();
        $this->student->assignRole('student');
        $this->student->studentProfile()->create([
            'skill_level' => SkillLevel::Beginner->value,
            'is_first_time' => false,
            'status' => 'active',
        ]);

        $this->instructor = User::factory()->instructor()->create();
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => 800,
        ]);

        $this->admin = User::factory()->admin()->create();
        $this->admin->assignRole('admin');

        $this->spot = SurfSpot::factory()->create(['difficulty' => 'beginner', 'is_active' => true]);
    }

    private function makeBooking(array $overrides = []): Booking
    {
        return Booking::factory()->withServiceFee(1200.00)->create(array_merge([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'date' => now()->addDays(3)->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
            'skill_level' => SkillLevel::Beginner->value,
            'student_age' => 25,
            'session_type' => SessionType::Teaching->value,
            'status' => BookingStatus::Confirmed,
        ], $overrides));
    }

    public function test_new_student_and_instructor_registrations_notify_admins_via_database_only(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Maya Tourist',
            'email' => 'maya@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'student',
        ])->assertRedirect(route('verification.notice'));

        $student = User::where('email', 'maya@example.com')->firstOrFail();

        Notification::assertSentTo(
            $this->admin,
            NewUserRegisteredNotification::class,
            function (NewUserRegisteredNotification $notification) use ($student): bool {
                $payload = $notification->toArray($this->admin);

                return $notification->via($this->admin) === ['database']
                    && $payload['type'] === 'new_user_registered'
                    && $payload['user_id'] === $student->id
                    && str_contains((string) $payload['url'], "/admin/students/{$student->id}");
            }
        );

        auth()->logout();
        Notification::fake();

        $this->post('/register', [
            'name' => 'Kuya Surfer',
            'email' => 'kuya@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'instructor',
        ])->assertRedirect(route('verification.notice'));

        $instructor = User::where('email', 'kuya@example.com')->firstOrFail();

        Notification::assertSentTo(
            $this->admin,
            NewUserRegisteredNotification::class,
            function (NewUserRegisteredNotification $notification) use ($instructor): bool {
                $payload = $notification->toArray($this->admin);

                return $notification->via($this->admin) === ['database']
                    && $payload['user_id'] === $instructor->id
                    && str_contains((string) $payload['url'], "/admin/instructors/{$instructor->id}");
            }
        );
    }

    public function test_instructor_onboarding_and_resubmission_notify_sisa_admins(): void
    {
        Storage::fake('local');
        Notification::fake();

        $applicant = User::factory()->instructor()->create();
        $applicant->assignRole('instructor');

        $this->actingAs($applicant)
            ->post(route('instructor.onboarding.store'), [
                'bio' => 'Certified Siargao surf instructor with 6 years of experience.',
                'level' => 2,
                'rate_per_hour' => 800,
                'certificates' => [UploadedFile::fake()->image('sisa_license.png')],
            ])
            ->assertRedirect(route('instructor.pending'));

        Notification::assertSentTo(
            $this->admin,
            InstructorPendingVerificationNotification::class,
            function (InstructorPendingVerificationNotification $notification) use ($applicant): bool {
                $payload = $notification->toArray($this->admin);

                return $notification->via($this->admin) === ['database', 'mail']
                    && $payload['instructor_id'] === $applicant->id
                    && $payload['is_resubmission'] === false
                    && str_contains((string) $payload['url'], "/admin/instructors/{$applicant->id}");
            }
        );

        Notification::fake();
        $applicant->instructorProfile()->update(['status' => InstructorStatus::Rejected->value]);

        $this->actingAs($applicant->fresh())
            ->post(route('instructor.onboarding.store'), [
                'bio' => 'Updated bio with renewed SISA certification.',
                'level' => 2,
                'rate_per_hour' => 800,
                'certificates' => [UploadedFile::fake()->image('renewed_cert.png')],
            ])
            ->assertRedirect(route('instructor.pending'));

        Notification::assertSentTo(
            $this->admin,
            InstructorPendingVerificationNotification::class,
            fn (InstructorPendingVerificationNotification $notification): bool => $notification->toArray($this->admin)['is_resubmission'] === true
        );
    }

    public function test_additional_certificate_upload_and_admin_review_notify_expected_parties(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->actingAs($this->instructor)
            ->post(route('instructor.certificates.store'), [
                'certificates' => [
                    UploadedFile::fake()->image('bls.png'),
                    UploadedFile::fake()->image('wasar.png'),
                ],
            ])
            ->assertRedirect();

        Notification::assertSentToTimes($this->admin, CertificateSubmittedNotification::class, 1);
        Notification::assertSentTo(
            $this->admin,
            CertificateSubmittedNotification::class,
            fn (CertificateSubmittedNotification $n): bool => $n->via($this->admin) === ['database']
                && $n->toArray($this->admin)['count'] === 2
        );

        $certificate = Certificate::where('user_id', $this->instructor->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.certificates.verify', $certificate), [
                'expires_at' => now()->addYear()->toDateString(),
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $this->instructor,
            CertificateStatusNotification::class,
            fn (CertificateStatusNotification $n): bool => $n->toArray($this->instructor)['type'] === 'certificate_verified'
        );

        Notification::fake();

        $secondCert = Certificate::where('user_id', $this->instructor->id)->latest('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.certificates.reject', $secondCert), [
                'notes' => 'Image is blurry.',
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $this->instructor,
            CertificateStatusNotification::class,
            fn (CertificateStatusNotification $n): bool => $n->toArray($this->instructor)['type'] === 'certificate_rejected'
                && str_contains($n->toArray($this->instructor)['message'], 'Image is blurry.')
        );
    }

    public function test_certificate_expiry_command_notifies_instructor_and_admin_and_deduplicates_warnings(): void
    {
        $expired = Certificate::create([
            'user_id' => $this->instructor->id,
            'type' => CertificateType::SISA,
            'file_path' => 'certificates/expired.png',
            'status' => CertificateStatus::Verified,
            'verified_at' => now()->subYear(),
            'expires_at' => now()->subDay(),
        ]);

        $expiringSoon = Certificate::create([
            'user_id' => $this->instructor->id,
            'type' => CertificateType::BLS,
            'file_path' => 'certificates/soon.png',
            'status' => CertificateStatus::Verified,
            'verified_at' => now()->subMonths(11),
            'expires_at' => now()->addDays(10),
        ]);

        $this->artisan('certs:expire')->assertSuccessful();

        $this->assertSame(CertificateStatus::Expired, $expired->fresh()->status);

        $this->assertSame(
            1,
            $this->instructor->notifications()->where('data->type', 'certificate_expired')->count()
        );
        $this->assertSame(
            1,
            $this->admin->notifications()->where('data->type', 'certificate_expired')->count()
        );
        $this->assertSame(
            1,
            $this->instructor->notifications()
                ->where('data->type', 'certificate_expiring_soon')
                ->where('data->certificate_id', $expiringSoon->id)
                ->count()
        );
        $this->assertSame(
            1,
            $this->admin->notifications()
                ->where('data->type', 'certificate_expiring_soon')
                ->where('data->certificate_id', $expiringSoon->id)
                ->count()
        );

        // Second sweep must not duplicate expiring-soon warnings.
        $this->artisan('certs:expire')->assertSuccessful();

        $this->assertSame(
            1,
            $this->instructor->fresh()->notifications()
                ->where('data->type', 'certificate_expiring_soon')
                ->where('data->certificate_id', $expiringSoon->id)
                ->count()
        );
        $this->assertSame(
            1,
            $this->admin->fresh()->notifications()
                ->where('data->type', 'certificate_expiring_soon')
                ->where('data->certificate_id', $expiringSoon->id)
                ->count()
        );
    }

    public function test_skill_upgrade_request_and_resolution_notify_admin_student_and_instructor(): void
    {
        Notification::fake();

        $completedBooking = $this->makeBooking([
            'status' => BookingStatus::Completed,
            'completed_at' => now()->subHour(),
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.request-upgrade', $completedBooking))
            ->assertRedirect();

        $upgrade = SkillUpgradeRequest::latest('id')->firstOrFail();

        Notification::assertSentTo(
            $this->admin,
            SkillUpgradeRequestedNotification::class,
            fn (SkillUpgradeRequestedNotification $n): bool => $n->toArray($this->admin)['skill_upgrade_request_id'] === $upgrade->id
        );

        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.skill-upgrades.approve', $upgrade))
            ->assertRedirect();

        Notification::assertSentTo(
            $this->student,
            SkillUpgradeResolvedNotification::class,
            fn (SkillUpgradeResolvedNotification $n): bool => $n->via($this->student) === ['database', 'mail']
                && $n->toArray($this->student)['type'] === 'skill_upgrade_approved'
        );
        Notification::assertSentTo(
            $this->instructor,
            SkillUpgradeResolvedNotification::class,
            fn (SkillUpgradeResolvedNotification $n): bool => $n->via($this->instructor) === ['database']
                && $n->toArray($this->instructor)['type'] === 'skill_upgrade_approved'
        );

        Notification::fake();

        $secondUpgrade = SkillUpgradeRequest::create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'booking_id' => $completedBooking->id,
            'current_level' => SkillLevel::Intermediate->value,
            'requested_level' => SkillLevel::Advanced->value,
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.skill-upgrades.reject', $secondUpgrade))
            ->assertRedirect();

        Notification::assertSentTo(
            $this->student,
            SkillUpgradeResolvedNotification::class,
            fn (SkillUpgradeResolvedNotification $n): bool => $n->toArray($this->student)['type'] === 'skill_upgrade_rejected'
        );
        Notification::assertSentTo(
            $this->instructor,
            SkillUpgradeResolvedNotification::class,
            fn (SkillUpgradeResolvedNotification $n): bool => $n->toArray($this->instructor)['type'] === 'skill_upgrade_rejected'
        );

        // Also verify completeSession() with upgrade_student=true notifies SISA admins.
        Notification::fake();

        $liveBooking = $this->makeBooking([
            'status' => BookingStatus::InProgress,
            'started_at' => now()->subHour(),
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.complete-session', $liveBooking), [
                'upgrade_student' => true,
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->admin, SkillUpgradeRequestedNotification::class);
    }

    public function test_gcash_receipt_upload_verify_and_reject_notify_expected_parties(): void
    {
        Storage::fake('local');
        Notification::fake();

        $booking = $this->makeBooking([
            'status' => BookingStatus::AwaitingPayment,
            'payment_deadline_at' => now()->addMinutes(12),
        ]);

        Payment::create([
            'booking_id' => $booking->id,
            'amount' => $booking->total_amount,
            'lesson_price' => $booking->lesson_price,
            'service_fee_amount' => $booking->service_fee_amount,
            'payment_method' => PaymentMethod::GCash,
            'status' => PaymentStatus::Pending,
        ]);

        Waiver::create([
            'booking_id' => $booking->id,
            'type' => 'liability',
            'signed_by' => $this->student->name,
            'signature_data' => 'data:image/png;base64,AAAA',
            'pdf_path' => 'waivers/test.pdf',
            'signed_at' => now(),
            'retention_until' => now()->addYears(7),
        ]);

        $this->actingAs($this->student)
            ->post(route('payments.receipt.upload', $booking), [
                'receipt' => UploadedFile::fake()->image('gcash.png'),
                'reference' => 'GCASH-9988',
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->instructor, GcashReceiptUploadedNotification::class);
        Notification::assertSentTo($this->admin, GcashReceiptUploadedNotification::class);

        // Instructor rejects receipt -> student notified
        Notification::fake();

        $this->actingAs($this->instructor)
            ->post(route('payments.receipt.reject', $booking), [
                'note' => 'Reference number unreadable.',
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $this->student,
            GcashReceiptRejectedNotification::class,
            fn (GcashReceiptRejectedNotification $n): bool => str_contains($n->toArray($this->student)['message'], 'Reference number unreadable.')
        );

        // Re-upload and instructor verifies -> student gets PaymentReceived, instructor does NOT self-notify
        $this->actingAs($this->student)
            ->post(route('payments.receipt.upload', $booking), [
                'receipt' => UploadedFile::fake()->image('gcash2.png'),
                'reference' => 'GCASH-9989',
            ])
            ->assertRedirect();

        Notification::fake();

        $this->actingAs($this->instructor)
            ->post(route('payments.receipt.verify', $booking))
            ->assertRedirect();

        Notification::assertSentToTimes($this->student, PaymentReceivedNotification::class, 1);
        Notification::assertSentToTimes($this->instructor, PaymentReceivedNotification::class, 1);

        // Admin verifies a separate receipt -> both student and instructor notified once
        $booking2 = $this->makeBooking([
            'status' => BookingStatus::AwaitingPayment,
            'payment_deadline_at' => now()->addMinutes(12),
            'date' => now()->addDays(4)->format('Y-m-d'),
        ]);

        Payment::create([
            'booking_id' => $booking2->id,
            'amount' => $booking2->total_amount,
            'lesson_price' => $booking2->lesson_price,
            'service_fee_amount' => $booking2->service_fee_amount,
            'payment_method' => PaymentMethod::GCash,
            'status' => PaymentStatus::PendingVerification,
            'receipt_path' => 'payment-receipts/sample.png',
            'receipt_uploaded_at' => now(),
        ]);

        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('payments.receipt.verify', $booking2))
            ->assertRedirect();

        Notification::assertSentToTimes($this->student, PaymentReceivedNotification::class, 1);
        Notification::assertSentToTimes($this->instructor, PaymentReceivedNotification::class, 1);
    }

    public function test_cash_mark_as_paid_and_underpayment_notify_expected_parties_without_actor_self_notify(): void
    {
        Notification::fake();

        $fullCashBooking = $this->makeBooking();
        Payment::factory()->cash()->create([
            'booking_id' => $fullCashBooking->id,
            'amount' => $fullCashBooking->total_amount,
            'lesson_price' => $fullCashBooking->lesson_price,
            'service_fee_amount' => $fullCashBooking->service_fee_amount,
            'status' => PaymentStatus::PendingCash->value,
        ]);

        $this->actingAs($this->instructor)
            ->post(route('payments.cash', $fullCashBooking), [
                'amount_received' => $fullCashBooking->total_amount,
            ])
            ->assertRedirect();

        Notification::assertSentToTimes($this->student, PaymentReceivedNotification::class, 1);
        Notification::assertSentToTimes($this->instructor, PaymentReceivedNotification::class, 1);

        // Underpayment marked by instructor -> student + admin notified
        Notification::fake();

        $underpaidBooking = $this->makeBooking(['date' => now()->addDays(4)->format('Y-m-d')]);
        Payment::factory()->cash()->create([
            'booking_id' => $underpaidBooking->id,
            'amount' => $underpaidBooking->total_amount,
            'lesson_price' => $underpaidBooking->lesson_price,
            'service_fee_amount' => $underpaidBooking->service_fee_amount,
            'status' => PaymentStatus::PendingCash->value,
        ]);

        $this->actingAs($this->instructor)
            ->post(route('payments.cash', $underpaidBooking), [
                'amount_received' => 500,
                'notes' => 'Student only had 500 pesos on hand.',
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->student, CashUnderpaymentFlaggedNotification::class);
        Notification::assertSentTo($this->admin, CashUnderpaymentFlaggedNotification::class);

        // Underpayment marked by admin -> student notified, acting admin does NOT self-notify
        Notification::fake();

        $adminUnderpaid = $this->makeBooking(['date' => now()->addDays(5)->format('Y-m-d')]);
        Payment::factory()->cash()->create([
            'booking_id' => $adminUnderpaid->id,
            'amount' => $adminUnderpaid->total_amount,
            'lesson_price' => $adminUnderpaid->lesson_price,
            'service_fee_amount' => $adminUnderpaid->service_fee_amount,
            'status' => PaymentStatus::PendingCash->value,
        ]);

        $this->actingAs($this->admin)
            ->post(route('payments.cash', $adminUnderpaid), [
                'amount_received' => 500,
                'notes' => 'Recorded partial cash at SISA desk.',
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->student, CashUnderpaymentFlaggedNotification::class);
        Notification::assertNotSentTo($this->admin, CashUnderpaymentFlaggedNotification::class);
    }

    public function test_online_gcash_settlement_and_redirect_fallback_notify_both_student_and_instructor(): void
    {
        Notification::fake();

        $booking = $this->makeBooking(['status' => BookingStatus::AwaitingPayment]);
        $payment = Payment::factory()->gcash()->create([
            'booking_id' => $booking->id,
            'amount' => $booking->total_amount,
            'lesson_price' => $booking->lesson_price,
            'service_fee_amount' => $booking->service_fee_amount,
            'transaction_id' => 'mock_tx_notif_1',
            'status' => PaymentStatus::Pending->value,
        ]);

        app(MockPaymentService::class)->simulatePayment($payment->transaction_id, true);

        Notification::assertSentToTimes($this->student, PaymentReceivedNotification::class, 1);
        Notification::assertSentToTimes($this->instructor, PaymentReceivedNotification::class, 1);

        // Redirect fallback path in PaymentController::success()
        Notification::fake();

        $booking2 = $this->makeBooking([
            'status' => BookingStatus::AwaitingPayment,
            'date' => now()->addDays(4)->format('Y-m-d'),
        ]);
        Payment::factory()->gcash()->create([
            'booking_id' => $booking2->id,
            'amount' => $booking2->total_amount,
            'lesson_price' => $booking2->lesson_price,
            'service_fee_amount' => $booking2->service_fee_amount,
            'transaction_id' => 'xendit_tx_redirect_1',
            'status' => PaymentStatus::Pending->value,
        ]);

        $mockGateway = $this->mock(PaymentServiceInterface::class);
        $mockGateway->shouldReceive('getPaymentStatus')
            ->with('xendit_tx_redirect_1')
            ->once()
            ->andReturn('paid');

        $this->actingAs($this->student)
            ->get(route('payments.success', $booking2))
            ->assertRedirect();

        Notification::assertSentToTimes($this->student, PaymentReceivedNotification::class, 1);
        Notification::assertSentToTimes($this->instructor, PaymentReceivedNotification::class, 1);
    }

    public function test_overdue_remittance_notifies_both_instructor_and_sisa_admin(): void
    {
        Notification::fake();

        InstructorDailyLedger::create([
            'instructor_id' => $this->instructor->id,
            'ledger_date' => now()->subDays(10)->toDateString(),
            'completed_bookings_count' => 1,
            'cash_bookings_count' => 1,
            'gcash_earnings' => 0.00,
            'cash_earnings' => 1200.00,
            'total_earnings' => 1200.00,
            'cash_service_fees_owed' => 84.00,
            'net_payable' => -84.00,
            'remittance_due_date' => now()->subDays(3)->toDateString(),
            'settlement_status' => LedgerSettlementStatus::Unsettled->value,
        ]);

        $this->artisan('ledger:check-remittances')->assertSuccessful();

        Notification::assertSentToTimes($this->instructor, CashRemittanceOverdueNotification::class, 1);
        Notification::assertSentToTimes($this->admin, CashRemittanceOverdueNotification::class, 1);
        Notification::assertSentTo(
            $this->admin,
            CashRemittanceOverdueNotification::class,
            fn (CashRemittanceOverdueNotification $n): bool => $n->via($this->admin) === ['database']
                && str_contains((string) $n->toArray($this->admin)['url'], "/admin/payouts/instructors/{$this->instructor->id}")
        );
    }

    public function test_student_ban_reviews_fines_and_strike_revocation_send_notifications(): void
    {
        Notification::fake();

        // 1. Student ban & unban
        $this->actingAs($this->admin)
            ->post(route('admin.students.toggle-status', $this->student))
            ->assertRedirect();

        Notification::assertSentTo(
            $this->student,
            StudentAccountStatusNotification::class,
            fn (StudentAccountStatusNotification $n): bool => $n->toArray($this->student)['type'] === 'student_account_banned'
        );

        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.students.toggle-status', $this->student))
            ->assertRedirect();

        Notification::assertSentTo(
            $this->student,
            StudentAccountStatusNotification::class,
            fn (StudentAccountStatusNotification $n): bool => $n->toArray($this->student)['type'] === 'student_account_unbanned'
        );

        // 2. Public review -> instructor; instructor reply -> student
        Notification::fake();

        $completedBooking = $this->makeBooking([
            'status' => BookingStatus::Completed,
            'completed_at' => now()->subHours(2),
        ]);

        $result = app(FeedbackService::class)->submit($completedBooking, $this->student, [
            'submit_review' => true,
            'submit_concern' => false,
            'rating' => 5,
            'comment' => 'Great patient coaching at Cloud 9 inner break today!',
        ]);

        Notification::assertSentTo($this->instructor, ReviewReceivedNotification::class);

        Notification::fake();

        /** @var Review $review */
        $review = $result['review'];

        $this->actingAs($this->instructor)
            ->post(route('instructor.reviews.respond', $review), [
                'response' => 'Thank you for surfing with us!',
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->student, ReviewResponseNotification::class);

        // 3. Fine settled (paid & waived)
        Notification::fake();

        $incident = SafetyIncident::create([
            'booking_id' => $completedBooking->id,
            'instructor_id' => $this->instructor->id,
            'reported_by' => $this->student->id,
            'source' => IncidentSource::StudentFeedback,
            'status' => IncidentStatus::Confirmed,
            'type' => IncidentType::RuleViolation,
            'final_outcome' => IncidentOutcome::OneStrike,
            'strike_weight' => 1,
            'description' => 'Safety violation verified by SISA.',
            'confirmed_at' => now(),
            'confirmed_by' => $this->admin->id,
        ]);

        $fine1 = InstructorFine::create([
            'instructor_id' => $this->instructor->id,
            'safety_incident_id' => $incident->id,
            'amount' => 500.00,
            'penalty_type' => '1_strike_fine',
            'status' => FineStatus::Due,
            'reason' => '1st strike fine.',
            'issued_by' => $this->admin->id,
            'issued_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.instructors.fines.mark-paid', [$this->instructor, $fine1]), [
                'settlement_note' => 'Paid at desk.',
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $this->instructor,
            FineSettledNotification::class,
            fn (FineSettledNotification $n): bool => $n->toArray($this->instructor)['status'] === 'paid'
        );

        Notification::fake();

        $fine2 = InstructorFine::create([
            'instructor_id' => $this->instructor->id,
            'safety_incident_id' => $incident->id,
            'amount' => 500.00,
            'penalty_type' => '1_strike_fine',
            'status' => FineStatus::Due,
            'reason' => 'Waivable fine.',
            'issued_by' => $this->admin->id,
            'issued_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.instructors.fines.waive', [$this->instructor, $fine2]), [
                'settlement_note' => 'Waived on appeal review.',
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $this->instructor,
            FineSettledNotification::class,
            fn (FineSettledNotification $n): bool => $n->toArray($this->instructor)['status'] === 'waived'
        );

        // 4. Strike revoked -> instructor notified
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.incidents.revoke', $incident), [
                'reason' => 'Overturned after reviewing beach witness evidence.',
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->instructor, StrikeRevokedNotification::class);
    }

    public function test_notification_feed_updated_broadcasts_on_role_channels_when_database_notification_sent(): void
    {
        $adminChannels = array_map(fn ($ch) => $ch->name, (new NotificationFeedUpdated($this->admin->id, 'admin'))->broadcastOn());
        $instructorChannels = array_map(fn ($ch) => $ch->name, (new NotificationFeedUpdated($this->instructor->id, 'instructor'))->broadcastOn());
        $studentChannels = array_map(fn ($ch) => $ch->name, (new NotificationFeedUpdated($this->student->id, 'student'))->broadcastOn());

        $this->assertSame(['private-admin.notifications'], $adminChannels);
        $this->assertSame(['private-instructor.'.$this->instructor->id], $instructorChannels);
        $this->assertSame(['private-student.'.$this->student->id], $studentChannels);

        Event::fake([NotificationFeedUpdated::class]);

        $this->admin->notify(new NewUserRegisteredNotification($this->student));

        Event::assertDispatched(NotificationFeedUpdated::class);
    }
}
