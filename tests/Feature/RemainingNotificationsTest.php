<?php

namespace Tests\Feature;

use App\Enums\AppealGround;
use App\Enums\AppealStatus;
use App\Enums\BookingStatus;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\FeedbackCategory;
use App\Enums\IncidentOutcome;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\InstructorLevel;
use App\Enums\InstructorStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundBasis;
use App\Enums\RefundStatus;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
use App\Enums\StrikeStatus;
use App\Enums\SuspensionStatus;
use App\Enums\SuspensionType;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\Certificate;
use App\Models\InstructorAppeal;
use App\Models\InstructorStrike;
use App\Models\InstructorSuspension;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Review;
use App\Models\SafetyIncident;
use App\Models\SurfSpot;
use App\Models\User;
use App\Notifications\AppealResolvedNotification;
use App\Notifications\AppealSubmittedNotification;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\BookingChangeRequestedNotification;
use App\Notifications\BookingDeclinedNotification;
use App\Notifications\BookingNoShowNotification;
use App\Notifications\BookingSpotChangedNotification;
use App\Notifications\CriticalIncidentNoticeNotification;
use App\Notifications\IncidentOutcomeNotification;
use App\Notifications\InstructorLevelUpdatedNotification;
use App\Notifications\InstructorReactivated;
use App\Notifications\InstructorStrikeRecordedNotification;
use App\Notifications\InstructorSuspended;
use App\Notifications\InstructorVerified;
use App\Notifications\NewIncidentReportNotification;
use App\Notifications\RefundStatusNotification;
use App\Notifications\ReviewModeratedNotification;
use App\Notifications\SendReviewRequest;
use App\Notifications\SessionCompletedNotification;
use App\Notifications\SisaIdExpiryNotification;
use App\Notifications\SkillUpgradeRequestedNotification;
use App\Notifications\SuspensionNotification;
use App\Notifications\SuspensionReadyForReinstatementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RemainingNotificationsTest extends TestCase
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

    public function test_admin_review_moderation_notifies_student_for_hide_unhide_and_delete(): void
    {
        Notification::fake();

        $booking = $this->makeBooking(['status' => BookingStatus::Completed]);
        $review = Review::create([
            'booking_id' => $booking->id,
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'rating' => 5,
            'comment' => 'Great lesson with clear safety coaching.',
            'is_hidden' => false,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.reviews.moderate', $review), [
                'action' => 'hide',
                'reason' => 'Contains unrelated promotional links.',
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $this->student,
            ReviewModeratedNotification::class,
            function (ReviewModeratedNotification $notification) use ($booking): bool {
                $payload = $notification->toArray($this->student);

                return $notification->via($this->student) === ['database', 'mail']
                    && $payload['type'] === 'review_hidden'
                    && $payload['reason'] === 'Contains unrelated promotional links.'
                    && str_contains($payload['message'], 'Contains unrelated promotional links.')
                    && $payload['url'] === route('student.bookings.show', $booking->id);
            }
        );

        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.reviews.moderate', $review), [
                'action' => 'unhide',
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $this->student,
            ReviewModeratedNotification::class,
            fn (ReviewModeratedNotification $n): bool => $n->toArray($this->student)['type'] === 'review_restored'
        );

        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.reviews.moderate', $review), [
                'action' => 'delete',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);

        Notification::assertSentTo(
            $this->student,
            ReviewModeratedNotification::class,
            function (ReviewModeratedNotification $notification) use ($booking): bool {
                $payload = $notification->toArray($this->student);

                return $payload['type'] === 'review_deleted'
                    && $payload['booking_id'] === $booking->id
                    && $payload['url'] === route('student.bookings.show', $booking->id);
            }
        );
        Notification::assertNotSentTo($this->instructor, ReviewModeratedNotification::class);
    }

    public function test_complete_session_notifies_student_via_database_only_both_normally_and_with_skill_upgrade(): void
    {
        Notification::fake();

        $booking = $this->makeBooking(['status' => BookingStatus::InProgress]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.complete-session', $booking))
            ->assertRedirect();

        Notification::assertSentTo(
            $this->student,
            SessionCompletedNotification::class,
            function (SessionCompletedNotification $notification) use ($booking): bool {
                $payload = $notification->toArray($this->student);

                return $notification->via($this->student) === ['database']
                    && $payload['type'] === 'session_completed'
                    && $payload['booking_id'] === $booking->id
                    && $payload['url'] === route('student.bookings.show', $booking->id);
            }
        );
        Notification::assertNotSentTo($this->instructor, SessionCompletedNotification::class);

        Notification::fake();

        $upgradeBooking = $this->makeBooking(['status' => BookingStatus::InProgress]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.complete-session', $upgradeBooking), [
                'upgrade_student' => true,
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $this->student,
            SessionCompletedNotification::class,
            fn (SessionCompletedNotification $n): bool => $n->toArray($this->student)['booking_id'] === $upgradeBooking->id
        );
        Notification::assertSentTo($this->admin, SkillUpgradeRequestedNotification::class);
    }

    public function test_critical_incident_store_and_confirm_notify_instructor_safely_only_without_manual_hold(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.incidents.store'), [
                'instructor_id' => $this->instructor->id,
                'category' => FeedbackCategory::CriticalNegligence->value,
                'type' => IncidentType::RuleViolation->value,
                'final_outcome' => IncidentOutcome::Critical->value,
                'description' => 'Detailed eyewitness report of severe safety violation in outer break.',
                'decision_reason' => 'Confirmed critical safety negligence requiring immediate external SISA action.',
                'admin_note' => 'Confidential internal whistleblower contact 09170001111.',
                'instructor_summary' => 'Please appear before the SISA board for an external critical safety review.',
                'place_manual_hold' => false,
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $this->instructor,
            CriticalIncidentNoticeNotification::class,
            function (CriticalIncidentNoticeNotification $notification): bool {
                $payload = $notification->toArray($this->instructor);

                return $notification->via($this->instructor) === ['database', 'mail']
                    && $payload['type'] === 'critical_incident_notice'
                    && $payload['url'] === route('instructor.dashboard')
                    && str_contains($payload['message'], 'SISA board')
                    && ! str_contains($payload['message'], 'Confidential internal')
                    && ! str_contains($payload['message'], '09170001111');
            }
        );
        Notification::assertNotSentTo($this->instructor, InstructorSuspended::class);

        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.incidents.store'), [
                'instructor_id' => $this->instructor->id,
                'category' => FeedbackCategory::CriticalNegligence->value,
                'type' => IncidentType::RuleViolation->value,
                'final_outcome' => IncidentOutcome::Critical->value,
                'description' => 'Second severe safety violation requiring immediate temporary suspension hold.',
                'decision_reason' => 'Confirmed critical misconduct requiring manual suspension hold.',
                'admin_note' => 'Internal board hold placed.',
                'instructor_summary' => 'Your account is on hold pending SISA critical safety hearing.',
                'place_manual_hold' => true,
                'manual_hold_days' => 14,
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->instructor, InstructorSuspended::class);
        Notification::assertNotSentTo($this->instructor, CriticalIncidentNoticeNotification::class);

        // Fresh un-suspended instructor for confirm() flow
        $secondInstructor = User::factory()->instructor()->create();
        $secondInstructor->assignRole('instructor');
        $secondInstructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => 800,
        ]);

        $pendingBooking = $this->makeBooking(['instructor_id' => $secondInstructor->id]);
        $pendingIncident = SafetyIncident::create([
            'booking_id' => $pendingBooking->id,
            'instructor_id' => $secondInstructor->id,
            'reported_by' => $this->student->id,
            'source' => IncidentSource::StudentFeedback,
            'status' => IncidentStatus::Pending,
            'category' => FeedbackCategory::CriticalNegligence->value,
            'type' => IncidentType::RuleViolation,
            'severity' => IncidentSeverity::Critical,
            'description' => 'Student private report describing dangerous abandonment.',
        ]);

        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.incidents.confirm', $pendingIncident), [
                'final_outcome' => IncidentOutcome::Critical->value,
                'decision_reason' => 'Confirmed critical incident for SISA external handling.',
                'admin_note' => 'Private reporter statement kept internal.',
                'instructor_summary' => 'Critical safety escalation logged for external SISA consultation.',
                'place_manual_hold' => false,
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $secondInstructor,
            CriticalIncidentNoticeNotification::class,
            function (CriticalIncidentNoticeNotification $notification) use ($secondInstructor): bool {
                $payload = $notification->toArray($secondInstructor);

                return str_contains($payload['message'], 'external SISA consultation')
                    && ! str_contains($payload['message'], 'Private reporter statement');
            }
        );
        Notification::assertSentTo($this->student, IncidentOutcomeNotification::class);
        Notification::assertNotSentTo($secondInstructor, InstructorSuspended::class);

        Notification::fake();

        $heldIncident = SafetyIncident::create([
            'booking_id' => $pendingBooking->id,
            'instructor_id' => $secondInstructor->id,
            'reported_by' => $this->student->id,
            'source' => IncidentSource::StudentFeedback,
            'status' => IncidentStatus::Pending,
            'category' => FeedbackCategory::CriticalNegligence->value,
            'type' => IncidentType::RuleViolation,
            'severity' => IncidentSeverity::Critical,
            'description' => 'Critical incident needing immediate hold.',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.incidents.confirm', $heldIncident), [
                'final_outcome' => IncidentOutcome::Critical->value,
                'decision_reason' => 'Confirmed critical violation with immediate manual suspension hold.',
                'admin_note' => 'Hold approved by SISA.',
                'instructor_summary' => 'Manual hold placed while SISA conducts external investigation.',
                'place_manual_hold' => true,
                'manual_hold_days' => 10,
            ])
            ->assertRedirect();

        Notification::assertSentTo($secondInstructor, InstructorSuspended::class);
        Notification::assertNotSentTo($secondInstructor, CriticalIncidentNoticeNotification::class);
    }

    public function test_admin_instructor_update_notifies_on_verification_and_level_change(): void
    {
        Storage::fake('local');
        Notification::fake();

        $pendingInstructor = User::factory()->instructor()->create();
        $pendingInstructor->assignRole('instructor');
        $pendingInstructor->instructorProfile()->create([
            'level' => InstructorLevel::Level1,
            'status' => InstructorStatus::PendingVerification->value,
            'rate_per_hour' => 700,
        ]);
        Certificate::create([
            'user_id' => $pendingInstructor->id,
            'type' => CertificateType::SISA,
            'file_path' => 'certificates/sisa-license.pdf',
            'status' => CertificateStatus::PendingVerification,
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.instructors.update', $pendingInstructor->id), [
                'name' => $pendingInstructor->name,
                'email' => $pendingInstructor->email,
                'level' => 2,
                'status' => InstructorStatus::Active->value,
                'rate_per_hour' => 800,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.instructors.show', $pendingInstructor->id));

        Notification::assertSentTo($pendingInstructor, InstructorVerified::class);
        Notification::assertSentTo(
            $pendingInstructor,
            InstructorLevelUpdatedNotification::class,
            function (InstructorLevelUpdatedNotification $notification) use ($pendingInstructor): bool {
                $payload = $notification->toArray($pendingInstructor);

                return $notification->via($pendingInstructor) === ['database', 'mail']
                    && $payload['type'] === 'instructor_level_updated'
                    && $payload['previous_level'] === 1
                    && $payload['new_level'] === 2
                    && $payload['url'] === route('instructor.profile.edit');
            }
        );
    }

    public function test_send_review_request_writes_to_database_and_mail_with_message_and_url(): void
    {
        Notification::fake();

        $booking = $this->makeBooking(['status' => BookingStatus::Completed]);
        Booking::whereKey($booking->id)->update(['updated_at' => now()->subMinutes(150)]);

        $this->artisan('reviews:send-requests')->assertSuccessful();

        Notification::assertSentTo(
            $this->student,
            SendReviewRequest::class,
            function (SendReviewRequest $notification) use ($booking): bool {
                $payload = $notification->toArray($this->student);

                return $notification->via($this->student) === ['database', 'mail']
                    && $payload['type'] === 'review_request'
                    && filled($payload['message'] ?? null)
                    && $payload['url'] === route('student.feedback.create', $booking->id);
            }
        );
    }

    public function test_legacy_notifications_resolve_role_aware_urls_and_feed_returns_them(): void
    {
        $booking = $this->makeBooking();

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 1284.00,
            'lesson_price' => 1200.00,
            'service_fee_amount' => 84.00,
            'payment_method' => PaymentMethod::GCash,
            'status' => PaymentStatus::Completed,
            'transaction_id' => 'txn_legacy_1',
        ]);

        $refund = Refund::create([
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'initiated_by' => $this->student->id,
            'lesson_price' => 1200.00,
            'service_fee_retained' => 84.00,
            'refund_percentage' => 100,
            'amount' => 1200.00,
            'instructor_retained_amount' => 0.00,
            'policy_type' => 'short_term',
            'basis' => RefundBasis::ShortTermFull,
            'days_before_session' => 10,
            'days_since_payment' => 1,
            'status' => RefundStatus::Completed,
            'gateway' => 'paymongo',
            'requested_at' => now(),
        ]);

        $suspension = InstructorSuspension::create([
            'instructor_id' => $this->instructor->id,
            'type' => SuspensionType::Automatic,
            'strike_tier' => 2,
            'status' => SuspensionStatus::Active,
            'reason' => 'Two active strikes.',
            'starts_at' => now()->subDays(8),
            'ends_at' => now()->subDay(),
        ]);

        $approvedAppeal = InstructorAppeal::create([
            'instructor_id' => $this->instructor->id,
            'instructor_suspension_id' => $suspension->id,
            'message' => 'Appeal explanation',
            'ground' => AppealGround::FactuallyIncorrect,
            'status' => AppealStatus::Approved,
        ]);

        $rejectedAppeal = InstructorAppeal::create([
            'instructor_id' => $this->instructor->id,
            'instructor_suspension_id' => $suspension->id,
            'message' => 'Second appeal explanation',
            'ground' => AppealGround::NewEvidence,
            'status' => AppealStatus::Rejected,
        ]);

        $incident = SafetyIncident::create([
            'booking_id' => $booking->id,
            'instructor_id' => $this->instructor->id,
            'reported_by' => $this->student->id,
            'source' => IncidentSource::StudentFeedback,
            'status' => IncidentStatus::Confirmed,
            'category' => FeedbackCategory::MinorLate->value,
            'type' => IncidentType::RuleViolation,
            'severity' => IncidentSeverity::Minor,
            'final_outcome' => IncidentOutcome::OneStrike,
            'description' => 'Instructor arrived 25 minutes late.',
        ]);

        $strike = InstructorStrike::create([
            'instructor_id' => $this->instructor->id,
            'safety_incident_id' => $incident->id,
            'weight' => 1,
            'reason_code' => FeedbackCategory::MinorLate->value,
            'reason_text' => 'Arrived late without prior notice.',
            'source' => IncidentSource::StudentFeedback,
            'status' => StrikeStatus::Active,
            'applied_by' => $this->admin->id,
            'applied_at' => now(),
        ]);

        $changeRequest = BookingChangeRequest::create([
            'booking_id' => $booking->id,
            'requested_by' => $this->student->id,
            'type' => ChangeRequestType::Cancellation,
            'status' => ChangeRequestStatus::Pending,
            'reason' => 'Sudden travel change.',
            'original_date' => $booking->date->format('Y-m-d'),
            'original_time_period' => 'morning',
        ]);

        $cancelled = new BookingCancelledNotification($booking, 'Weather check');
        $this->assertSame(route('student.bookings.show', $booking->id), $cancelled->toArray($this->student)['url']);
        $this->assertSame(route('instructor.bookings.show', $booking->id), $cancelled->toArray($this->instructor)['url']);
        $this->assertSame(route('admin.bookings.show', $booking->id), $cancelled->toArray($this->admin)['url']);

        $refundNotification = new RefundStatusNotification($refund);
        $this->assertSame(route('student.bookings.show', $booking->id), $refundNotification->toArray($this->student)['url']);
        $this->assertSame(route('instructor.bookings.show', $booking->id), $refundNotification->toArray($this->instructor)['url']);
        $this->assertSame(route('admin.refunds.index'), $refundNotification->toArray($this->admin)['url']);

        $this->assertSame(
            route('instructor.dashboard'),
            (new AppealResolvedNotification($approvedAppeal))->toArray($this->instructor)['url']
        );
        $this->assertSame(
            route('instructor.suspended'),
            (new AppealResolvedNotification($rejectedAppeal))->toArray($this->instructor)['url']
        );
        $this->assertSame(
            route('admin.appeals.show', $approvedAppeal),
            (new AppealSubmittedNotification($approvedAppeal))->toArray($this->admin)['url']
        );
        $this->assertSame(
            route('admin.change-requests.index'),
            (new BookingChangeRequestedNotification($changeRequest))->toArray($this->admin)['url']
        );
        $this->assertSame(
            route('student.bookings.show', $booking->id),
            (new BookingDeclinedNotification($booking, false))->toArray($this->student)['url']
        );
        $this->assertSame(
            route('student.bookings.show', $booking->id),
            (new BookingNoShowNotification($booking, 1200.00))->toArray($this->student)['url']
        );
        $this->assertSame(
            route('student.bookings.show', $booking->id),
            (new BookingSpotChangedNotification($booking, 'Old Spot', 'New Spot', 'Safer tide'))->toArray($this->student)['url']
        );
        $this->assertSame(
            route('student.bookings.show', $booking->id),
            (new IncidentOutcomeNotification($incident))->toArray($this->student)['url']
        );
        $this->assertSame(
            route('instructor.dashboard'),
            (new InstructorReactivated)->toArray($this->instructor)['url']
        );
        $this->assertSame(
            route('instructor.dashboard'),
            (new InstructorStrikeRecordedNotification($strike, 1))->toArray($this->instructor)['url']
        );
        $this->assertSame(
            route('instructor.suspended'),
            (new InstructorSuspended('Safety review', 7, 2, false))->toArray($this->instructor)['url']
        );
        $this->assertSame(
            route('instructor.profile.edit'),
            (new InstructorVerified($this->instructor->instructorProfile))->toArray($this->instructor)['url']
        );
        $this->assertSame(
            route('admin.incidents.show', $incident),
            (new NewIncidentReportNotification($incident))->toArray($this->admin)['url']
        );
        $this->assertSame(
            route('instructor.suspended'),
            (new SuspensionNotification($incident))->toArray($this->instructor)['url']
        );
        $this->assertSame(
            route('admin.instructors.show', $this->instructor->id),
            (new SuspensionReadyForReinstatementNotification($suspension))->toArray($this->admin)['url']
        );

        // Verify persisted feed exposes the explicit url directly
        $this->student->notify(new SessionCompletedNotification($booking));

        $this->actingAs($this->student->fresh())
            ->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonPath('items.0.type', 'session_completed')
            ->assertJsonPath('items.0.url', route('student.bookings.show', $booking->id));
    }

    public function test_sisa_id_expiry_command_notifies_30_days_before_and_when_expired_without_duplicates(): void
    {
        $expiringInstructor = User::factory()->instructor()->create(['name' => 'Expiring ID Instructor']);
        $expiringInstructor->assignRole('instructor');
        $expiringInstructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => 800,
        ]);
        Certificate::create([
            'user_id' => $expiringInstructor->id,
            'type' => CertificateType::SISA,
            'file_path' => 'certificates/expiring-id.pdf',
            'status' => CertificateStatus::Verified,
            'verified_at' => now()->subYears(2)->addDays(15),
            'expires_at' => null,
        ]);

        $expiredByDefaultWindow = User::factory()->instructor()->create(['name' => 'Lapsed 2Y ID Instructor']);
        $expiredByDefaultWindow->assignRole('instructor');
        $expiredByDefaultWindow->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => 800,
        ]);
        Certificate::create([
            'user_id' => $expiredByDefaultWindow->id,
            'type' => CertificateType::SISA,
            'file_path' => 'certificates/lapsed-2y-id.pdf',
            'status' => CertificateStatus::Verified,
            'verified_at' => now()->subYears(2)->subDays(2),
            'expires_at' => null,
        ]);

        $expiredByCertDate = User::factory()->instructor()->create(['name' => 'Expired SISA Cert ID Instructor']);
        $expiredByCertDate->assignRole('instructor');
        $expiredByCertDate->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => 800,
        ]);
        Certificate::create([
            'user_id' => $expiredByCertDate->id,
            'type' => CertificateType::SISA,
            'file_path' => 'certificates/expired-cert-id.pdf',
            'status' => CertificateStatus::Verified,
            'verified_at' => now()->subYear(),
            'expires_at' => now()->subDay(),
        ]);

        // Verify channel routing directly
        $sampleNotification = new SisaIdExpiryNotification(
            $expiringInstructor,
            'SISA 2024-0001',
            now()->addDays(15),
            expired: false,
            daysLeft: 15
        );
        $this->assertSame(['database', 'mail'], $sampleNotification->via($expiringInstructor));
        $this->assertSame(['database'], $sampleNotification->via($this->admin));

        $this->artisan('certs:expire')->assertSuccessful();

        // Expiring within 30 days -> instructor & admin notified with correct URLs
        $expiringNotice = $expiringInstructor->notifications()
            ->where('data->type', 'sisa_id_expiring_soon')
            ->first();
        $this->assertNotNull($expiringNotice);
        $this->assertSame(route('instructor.qr.show'), $expiringNotice->data['url']);

        $adminExpiringNotice = $this->admin->notifications()
            ->where('data->type', 'sisa_id_expiring_soon')
            ->where('data->instructor_id', $expiringInstructor->id)
            ->first();
        $this->assertNotNull($adminExpiringNotice);
        $this->assertSame(route('admin.instructors.show', $expiringInstructor->id), $adminExpiringNotice->data['url']);

        // Expired by 2-year default window & expired by explicit SISA cert expires_at
        foreach ([$expiredByDefaultWindow, $expiredByCertDate] as $expiredInstructor) {
            $instructorNotice = $expiredInstructor->notifications()
                ->where('data->type', 'sisa_id_expired')
                ->first();
            $this->assertNotNull($instructorNotice);
            $this->assertSame(route('instructor.qr.show'), $instructorNotice->data['url']);

            $adminExpiredNotice = $this->admin->notifications()
                ->where('data->type', 'sisa_id_expired')
                ->where('data->instructor_id', $expiredInstructor->id)
                ->first();
            $this->assertNotNull($adminExpiredNotice);
            $this->assertSame(route('admin.instructors.show', $expiredInstructor->id), $adminExpiredNotice->data['url']);
        }

        // Second sweep must not duplicate expiring-soon or expired ID notifications
        $this->artisan('certs:expire')->assertSuccessful();

        $this->assertSame(
            1,
            $expiringInstructor->fresh()->notifications()->where('data->type', 'sisa_id_expiring_soon')->count()
        );
        $this->assertSame(
            1,
            $this->admin->fresh()->notifications()
                ->where('data->type', 'sisa_id_expiring_soon')
                ->where('data->instructor_id', $expiringInstructor->id)
                ->count()
        );
        foreach ([$expiredByDefaultWindow, $expiredByCertDate] as $expiredInstructor) {
            $this->assertSame(
                1,
                $expiredInstructor->fresh()->notifications()->where('data->type', 'sisa_id_expired')->count()
            );
            $this->assertSame(
                1,
                $this->admin->fresh()->notifications()
                    ->where('data->type', 'sisa_id_expired')
                    ->where('data->instructor_id', $expiredInstructor->id)
                    ->count()
            );
        }
    }
}
