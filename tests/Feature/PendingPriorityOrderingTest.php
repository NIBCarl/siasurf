<?php

namespace Tests\Feature;

use App\Enums\AppealStatus;
use App\Enums\BookingStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\InstructorLevel;
use App\Enums\InstructorStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundBasis;
use App\Enums\RefundStatus;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\InstructorAppeal;
use App\Models\InstructorSuspension;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\SafetyIncident;
use App\Models\SkillUpgradeRequest;
use App\Models\SurfSpot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PendingPriorityOrderingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $instructor;

    private User $student;

    private SurfSpot $spot;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['student', 'instructor', 'admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->admin = User::factory()->admin()->create();
        $this->admin->assignRole('admin');

        $this->instructor = User::factory()->instructor()->create();
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2->value,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => 800,
        ]);

        $this->student = User::factory()->student()->create();
        $this->student->assignRole('student');
        $this->student->studentProfile()->create([
            'skill_level' => 'beginner',
            'is_first_time' => false,
            'status' => 'active',
        ]);

        $this->spot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
    }

    public function test_admin_incidents_index_prioritizes_pending_newest_first(): void
    {
        $olderPending = SafetyIncident::create([
            'instructor_id' => $this->instructor->id,
            'reported_by' => $this->student->id,
            'source' => IncidentSource::StudentFeedback->value,
            'status' => IncidentStatus::Pending->value,
            'type' => 'rule_violation',
            'severity' => 'minor',
            'description' => 'Older pending incident',
        ]);
        $olderPending->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $newerPending = SafetyIncident::create([
            'instructor_id' => $this->instructor->id,
            'reported_by' => $this->student->id,
            'source' => IncidentSource::StudentFeedback->value,
            'status' => IncidentStatus::Pending->value,
            'type' => 'rule_violation',
            'severity' => 'minor',
            'description' => 'Newer pending incident',
        ]);
        $newerPending->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $newestDismissed = SafetyIncident::create([
            'instructor_id' => $this->instructor->id,
            'reported_by' => $this->student->id,
            'source' => IncidentSource::StudentFeedback->value,
            'status' => IncidentStatus::Rejected->value,
            'type' => 'rule_violation',
            'severity' => 'minor',
            'description' => 'Newest dismissed incident',
            'rejected_at' => now(),
            'rejected_by' => $this->admin->id,
        ]);
        $newestDismissed->forceFill(['created_at' => now()])->saveQuietly();

        $this->actingAs($this->admin)
            ->get(route('admin.incidents.index', ['status' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('incidents.data.0.id', $newerPending->id)
                ->where('incidents.data.1.id', $olderPending->id)
                ->where('incidents.data.2.id', $newestDismissed->id)
            );
    }

    public function test_admin_skill_upgrades_index_prioritizes_pending_newest_first(): void
    {
        $olderPending = SkillUpgradeRequest::create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'current_level' => 'beginner',
            'requested_level' => 'intermediate',
            'status' => 'pending',
        ]);
        $olderPending->forceFill(['created_at' => now()->subDays(3)])->save();

        $newerPending = SkillUpgradeRequest::create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'current_level' => 'beginner',
            'requested_level' => 'intermediate',
            'status' => 'pending',
        ]);
        $newerPending->forceFill(['created_at' => now()->subDay()])->save();

        $newestApproved = SkillUpgradeRequest::create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'current_level' => 'beginner',
            'requested_level' => 'intermediate',
            'status' => 'approved',
        ]);
        $newestApproved->forceFill(['created_at' => now()])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.skill-upgrades.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('upgradeRequests.data.0.id', $newerPending->id)
                ->where('upgradeRequests.data.1.id', $olderPending->id)
                ->where('upgradeRequests.data.2.id', $newestApproved->id)
            );
    }

    public function test_admin_appeals_index_prioritizes_pending_newest_first(): void
    {
        $suspension = InstructorSuspension::create([
            'instructor_id' => $this->instructor->id,
            'type' => 'manual',
            'status' => 'active',
            'reason' => 'Test suspension',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(6),
            'suspended_by' => $this->admin->id,
        ]);

        $olderPending = InstructorAppeal::create([
            'instructor_id' => $this->instructor->id,
            'instructor_suspension_id' => $suspension->id,
            'message' => str_repeat('Older appeal message ', 4),
            'status' => AppealStatus::Pending->value,
        ]);
        $olderPending->forceFill(['created_at' => now()->subDays(3)])->save();

        $newerPending = InstructorAppeal::create([
            'instructor_id' => $this->instructor->id,
            'instructor_suspension_id' => $suspension->id,
            'message' => str_repeat('Newer appeal message ', 4),
            'status' => AppealStatus::Pending->value,
        ]);
        $newerPending->forceFill(['created_at' => now()->subDay()])->save();

        $newestApproved = InstructorAppeal::create([
            'instructor_id' => $this->instructor->id,
            'instructor_suspension_id' => $suspension->id,
            'message' => str_repeat('Newest approved appeal ', 4),
            'status' => AppealStatus::Approved->value,
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
        ]);
        $newestApproved->forceFill(['created_at' => now()])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.appeals.index', ['status' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('appeals.data.0.id', $newerPending->id)
                ->where('appeals.data.1.id', $olderPending->id)
                ->where('appeals.data.2.id', $newestApproved->id)
            );
    }

    public function test_admin_change_requests_index_prioritizes_pending_newest_first(): void
    {
        $booking = Booking::factory()->confirmed()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
        ]);

        $olderPending = BookingChangeRequest::create([
            'booking_id' => $booking->id,
            'requested_by' => $this->instructor->id,
            'type' => 'reschedule',
            'status' => ChangeRequestStatus::Pending->value,
            'requires_admin_approval' => true,
            'reason' => 'Older change request',
        ]);
        $olderPending->forceFill(['created_at' => now()->subDays(3)])->save();

        $newerPending = BookingChangeRequest::create([
            'booking_id' => $booking->id,
            'requested_by' => $this->instructor->id,
            'type' => 'reschedule',
            'status' => ChangeRequestStatus::Pending->value,
            'requires_admin_approval' => true,
            'reason' => 'Newer change request',
        ]);
        $newerPending->forceFill(['created_at' => now()->subDay()])->save();

        $newestApproved = BookingChangeRequest::create([
            'booking_id' => $booking->id,
            'requested_by' => $this->instructor->id,
            'type' => 'reschedule',
            'status' => ChangeRequestStatus::Approved->value,
            'requires_admin_approval' => true,
            'reason' => 'Newest approved change request',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
        ]);
        $newestApproved->forceFill(['created_at' => now()])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.change-requests.index', ['status' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('changeRequests.data.0.id', $newerPending->id)
                ->where('changeRequests.data.1.id', $olderPending->id)
                ->where('changeRequests.data.2.id', $newestApproved->id)
            );
    }

    public function test_admin_refunds_index_prioritizes_actionable_refunds_newest_first(): void
    {
        $booking = Booking::factory()->confirmed()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 1284,
            'lesson_price' => 1200,
            'service_fee_amount' => 84,
            'payment_method' => PaymentMethod::GCash->value,
            'status' => PaymentStatus::Completed->value,
            'paid_at' => now()->subDays(5),
        ]);

        $olderActionable = Refund::create([
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'initiated_by' => $this->student->id,
            'lesson_price' => 1200,
            'service_fee_retained' => 84,
            'refund_percentage' => 100,
            'amount' => 1200,
            'instructor_retained_amount' => 0,
            'policy_type' => 'short_term',
            'basis' => RefundBasis::ShortTermFull->value,
            'status' => RefundStatus::ManualRequired->value,
            'gateway' => 'manual_gcash',
        ]);
        $olderActionable->forceFill(['created_at' => now()->subDays(3)])->save();

        $newerActionable = Refund::create([
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'initiated_by' => $this->student->id,
            'lesson_price' => 1200,
            'service_fee_retained' => 84,
            'refund_percentage' => 100,
            'amount' => 1200,
            'instructor_retained_amount' => 0,
            'policy_type' => 'short_term',
            'basis' => RefundBasis::ShortTermFull->value,
            'status' => RefundStatus::Requested->value,
            'gateway' => 'mock',
        ]);
        $newerActionable->forceFill(['created_at' => now()->subDay()])->save();

        $newestCompleted = Refund::create([
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'initiated_by' => $this->student->id,
            'lesson_price' => 1200,
            'service_fee_retained' => 84,
            'refund_percentage' => 100,
            'amount' => 1200,
            'instructor_retained_amount' => 0,
            'policy_type' => 'short_term',
            'basis' => RefundBasis::ShortTermFull->value,
            'status' => RefundStatus::Completed->value,
            'gateway' => 'mock',
            'completed_at' => now(),
        ]);
        $newestCompleted->forceFill(['created_at' => now()])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.refunds.index', ['status' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('refunds.data.0.id', $newerActionable->id)
                ->where('refunds.data.1.id', $olderActionable->id)
                ->where('refunds.data.2.id', $newestCompleted->id)
            );
    }

    public function test_instructor_bookings_index_prioritizes_pending_newest_first(): void
    {
        $olderPending = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'status' => BookingStatus::Pending->value,
            'accept_deadline_at' => now()->addHours(2),
            'date' => now()->addDays(4)->format('Y-m-d'),
        ]);
        $olderPending->forceFill(['created_at' => now()->subDays(3)])->save();

        $newerPending = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'status' => BookingStatus::Pending->value,
            'accept_deadline_at' => now()->addHours(2),
            'date' => now()->addDays(3)->format('Y-m-d'),
        ]);
        $newerPending->forceFill(['created_at' => now()->subDay()])->save();

        $newestConfirmed = Booking::factory()->confirmed()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'date' => now()->addDays(10)->format('Y-m-d'),
        ]);
        $newestConfirmed->forceFill(['created_at' => now()])->save();

        $this->actingAs($this->instructor)
            ->get(route('instructor.bookings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('bookings.data.0.id', $newerPending->id)
                ->where('bookings.data.1.id', $olderPending->id)
                ->where('bookings.data.2.id', $newestConfirmed->id)
            );
    }
}
