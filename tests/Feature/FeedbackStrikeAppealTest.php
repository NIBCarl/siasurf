<?php

namespace Tests\Feature;

use App\Enums\InstructorLevel;
use App\Enums\InstructorStatus;
use App\Enums\StrikeStatus;
use App\Enums\SuspensionStatus;
use App\Models\Booking;
use App\Models\InstructorSuspension;
use App\Models\SafetyIncident;
use App\Models\SurfSpot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeedbackStrikeAppealTest extends TestCase
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

        $this->student = User::factory()->student()->create();
        $this->student->assignRole('student');

        $this->instructor = User::factory()->instructor()->create();
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 1000,
            'strike_count' => 0,
        ]);

        $this->admin = User::factory()->admin()->create();
        $this->admin->assignRole('admin');

        $this->spot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
    }

    private function booking(array $attributes = []): Booking
    {
        return Booking::factory()->completed()->create(array_merge([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spot->id,
            'date' => now()->subDay()->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'duration_hours' => 1,
            'student_age' => 25,
            'student_count' => 1,
        ], $attributes));
    }

    private function submitConcern(Booking $booking, string $category = 'minor_late'): SafetyIncident
    {
        $response = $this->actingAs($this->student)->post(route('student.feedback.store', $booking), [
            'submit_review' => false,
            'submit_concern' => true,
            'concern_category' => $category,
            'concern_details' => 'The instructor started the session late and did not explain the delay to the group.',
            'injury_occurred' => false,
            'near_miss' => false,
            'truth_declaration' => true,
        ]);

        $response->assertRedirect(route('student.bookings.show', $booking));

        return SafetyIncident::where('booking_id', $booking->id)
            ->where('source', 'student_feedback')
            ->latest()
            ->firstOrFail();
    }

    private function confirm(SafetyIncident $incident, string $severity = 'minor', string $category = 'minor_late')
    {
        return $this->actingAs($this->admin)->post(route('admin.incidents.confirm', $incident), [
            'severity' => $severity,
            'category' => $category,
            'instructor_summary' => 'SISA confirmed the session concern.',
            'admin_note' => 'Reviewed against the booking and report.',
        ]);
    }

    public function test_positive_review_creates_no_report_and_no_strike(): void
    {
        $booking = $this->booking();

        $response = $this->actingAs($this->student)->post(route('student.feedback.store', $booking), [
            'submit_review' => true,
            'submit_concern' => false,
            'rating' => 5,
            'comment' => 'Very clear coaching and I felt safe throughout the lesson.',
            'feedback_tags' => ['positive_coaching', 'positive_safety'],
        ]);

        $response->assertRedirect(route('student.bookings.show', $booking));
        $this->assertDatabaseHas('reviews', ['booking_id' => $booking->id, 'rating' => 5]);
        $this->assertDatabaseCount('safety_incidents', 0);
        $this->assertSame(0, $this->instructor->instructorProfile->fresh()->strike_count);
    }

    public function test_concern_starts_pending_and_does_not_apply_a_strike(): void
    {
        $incident = $this->submitConcern($this->booking());

        $this->assertSame('pending', $incident->status->value);
        $this->assertSame(0, $incident->strike_weight);
        $this->assertSame('student_feedback', $incident->source->value);
        $this->assertSame(0, $this->instructor->instructorProfile->fresh()->strike_count);
        $this->assertDatabaseCount('instructor_strikes', 0);
    }

    public function test_admin_confirmation_applies_the_expected_strike_weight(): void
    {
        $incident = $this->submitConcern($this->booking(), 'minor_late');

        $this->confirm($incident, 'minor', 'minor_late')->assertRedirect();

        $incident->refresh();
        $this->assertSame('confirmed', $incident->status->value);
        $this->assertSame(1, $incident->strike_weight);
        $this->assertSame(1, $this->instructor->instructorProfile->fresh()->strike_count);
        $this->assertDatabaseHas('instructor_strikes', [
            'instructor_id' => $this->instructor->id,
            'safety_incident_id' => $incident->id,
            'weight' => 1,
            'status' => StrikeStatus::Active->value,
        ]);
    }

    public function test_three_separate_minor_confirmations_trigger_a_suspension(): void
    {
        foreach ([1, 2, 3] as $index) {
            $booking = $this->booking(['date' => now()->subDays($index + 1)->format('Y-m-d')]);
            $incident = $this->submitConcern($booking, 'minor_late');
            $this->confirm($incident, 'minor', 'minor_late');
        }

        $profile = $this->instructor->instructorProfile->fresh();
        $this->assertSame(InstructorStatus::Suspended, $profile->status);
        $this->assertSame(3, $profile->strike_count);
        $this->assertNotNull($profile->suspended_until);
        $this->assertStringContainsString('3 active strike', (string) $profile->suspension_reason);

        $suspension = InstructorSuspension::where('instructor_id', $this->instructor->id)
            ->where('status', SuspensionStatus::Active->value)
            ->firstOrFail();

        $this->assertSame('automatic', $suspension->type->value);
    }

    public function test_one_critical_confirmation_immediately_suspends(): void
    {
        $incident = $this->submitConcern($this->booking(), 'critical_harassment');

        $this->confirm($incident, 'critical', 'critical_harassment')->assertRedirect();

        $profile = $this->instructor->instructorProfile->fresh();
        $this->assertSame(InstructorStatus::Suspended, $profile->status);
        $this->assertSame(3, $profile->strike_count);
        $this->assertDatabaseHas('instructor_suspensions', [
            'instructor_id' => $this->instructor->id,
            'type' => 'automatic',
            'status' => 'active',
        ]);
    }

    public function test_admin_rejection_does_not_create_a_strike(): void
    {
        $incident = $this->submitConcern($this->booking());

        $this->actingAs($this->admin)
            ->post(route('admin.incidents.reject', $incident), [
                'reason' => 'The booking and GPS records contradict this account.',
            ])
            ->assertRedirect();

        $this->assertSame('rejected', $incident->fresh()->status->value);
        $this->assertSame(0, $this->instructor->instructorProfile->fresh()->strike_count);
        $this->assertDatabaseCount('instructor_strikes', 0);
    }

    public function test_one_student_report_per_booking(): void
    {
        $booking = $this->booking();
        $this->submitConcern($booking);

        $response = $this->actingAs($this->student)->post(route('student.feedback.store', $booking), [
            'submit_review' => false,
            'submit_concern' => true,
            'concern_category' => 'minor_communication',
            'concern_details' => 'A second duplicate concern should not be allowed for the same completed booking.',
            'truth_declaration' => true,
        ]);

        $response->assertSessionHasErrors('concern');
        $this->assertSame(1, SafetyIncident::where('booking_id', $booking->id)->count());
    }


    public function test_suspended_page_shows_the_reason_and_appeal_button(): void
    {
        $incident = $this->submitConcern($this->booking(), 'critical_harassment');
        $this->confirm($incident, 'critical', 'critical_harassment');

        $response = $this->actingAs($this->instructor)->get(route('instructor.suspended'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Instructor/Suspended')
            ->where('profile.strike_count', 3)
            ->where('canAppeal', true)
            ->where('suspension.type', 'automatic')
            ->where('suspension.reason', fn ($reason) => is_string($reason) && str_contains($reason, '3 active strike'))
        );
    }

    public function test_suspended_instructor_is_kept_off_the_booking_workspace(): void
    {
        $incident = $this->submitConcern($this->booking(), 'critical_harassment');
        $this->confirm($incident, 'critical', 'critical_harassment');

        $this->actingAs($this->instructor)
            ->get(route('instructor.bookings.index'))
            ->assertRedirect(route('instructor.suspended'));
    }

    public function test_instructor_can_appeal_and_admin_can_overturn_and_revoke_the_strike(): void
    {
        $incident = $this->submitConcern($this->booking(), 'critical_harassment');
        $this->confirm($incident, 'critical', 'critical_harassment');

        $this->actingAs($this->instructor)
            ->post(route('instructor.suspension.appeal'), [
                'message' => 'I was not the instructor assigned to this session. My booking records and GPS location show I was teaching another student at a different break.',
                'ground' => 'not_my_session',
                'truth_declaration' => true,
            ])
            ->assertRedirect();

        $appeal = \App\Models\InstructorAppeal::where('instructor_id', $this->instructor->id)->firstOrFail();
        $this->assertSame('pending', $appeal->status->value);

        $this->actingAs($this->admin)
            ->post(route('admin.appeals.approve', $appeal), [
                'response' => 'The booking and GPS records confirm the instructor was not at this session. The strike is overturned.',
                'revoke_incident_ids' => [$incident->id],
            ])
            ->assertRedirect();

        $appeal->refresh();
        $this->assertSame('approved', $appeal->status->value);
        $this->assertSame(InstructorStatus::Active, $this->instructor->instructorProfile->fresh()->status);
        $this->assertSame(0, $this->instructor->instructorProfile->fresh()->strike_count);
        $this->assertDatabaseHas('instructor_strikes', [
            'safety_incident_id' => $incident->id,
            'status' => StrikeStatus::Revoked->value,
        ]);
        $this->assertDatabaseHas('instructor_suspensions', [
            'instructor_id' => $this->instructor->id,
            'status' => SuspensionStatus::LiftedOnAppeal->value,
        ]);
    }

    public function test_admin_can_suspend_manually_and_the_reason_is_visible(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.instructors.suspend', $this->instructor), [
                'reason' => 'SISA is investigating a serious off-platform safety complaint.',
                'duration_days' => 14,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('instructor_suspensions', [
            'instructor_id' => $this->instructor->id,
            'type' => 'manual',
            'status' => 'active',
            'reason' => 'SISA is investigating a serious off-platform safety complaint.',
        ]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.suspended'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('suspension.reason', 'SISA is investigating a serious off-platform safety complaint.')
                ->where('canAppeal', true)
            );
    }

    public function test_admin_can_log_a_direct_incident_and_apply_its_strike(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.incidents.store'), [
                'instructor_id' => $this->instructor->id,
                'booking_id' => null,
                'category' => 'major_conditions_ignored',
                'type' => 'rule_violation',
                'severity' => 'major',
                'description' => 'The instructor took a beginner group into a strong current after a low-tide warning.',
                'location' => 'Cloud 9',
                'instructor_summary' => 'Took a beginner group into unsafe conditions after a warning.',
            ])
            ->assertRedirect();

        $this->assertSame(2, $this->instructor->instructorProfile->fresh()->strike_count);
        $this->assertDatabaseHas('safety_incidents', [
            'instructor_id' => $this->instructor->id,
            'status' => 'confirmed',
            'source' => 'admin',
            'strike_weight' => 2,
        ]);
    }


    public function test_admin_can_revoke_a_confirmed_incident_and_lift_the_automatic_suspension(): void
    {
        $incident = $this->submitConcern($this->booking(), 'critical_harassment');
        $this->confirm($incident, 'critical', 'critical_harassment');

        $this->actingAs($this->admin)
            ->post(route('admin.incidents.revoke', $incident), [
                'reason' => 'New evidence shows the report was filed against the wrong instructor.',
            ])
            ->assertRedirect();

        $this->assertSame('revoked', $incident->fresh()->status->value);
        $this->assertSame(InstructorStatus::Active, $this->instructor->instructorProfile->fresh()->status);
        $this->assertSame(0, $this->instructor->instructorProfile->fresh()->strike_count);
        $this->assertDatabaseHas('instructor_suspensions', [
            'instructor_id' => $this->instructor->id,
            'status' => SuspensionStatus::RevokedByAdmin->value,
        ]);
    }


    public function test_feedback_and_admin_report_pages_render(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->student)
            ->get(route('student.feedback.create', $booking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Student/Feedback/Create')
                ->where('canReview', true)
                ->where('canReport', true)
            );

        $incident = $this->submitConcern($booking);

        $this->actingAs($this->admin)
            ->get(route('admin.incidents.show', $incident))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Incidents/Show')
                ->where('incident.status', 'pending')
            );
    }

}
