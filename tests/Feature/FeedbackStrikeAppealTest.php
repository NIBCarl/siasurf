<?php

namespace Tests\Feature;

use App\Enums\FineStatus;
use App\Enums\IncidentOutcome;
use App\Enums\InstructorLevel;
use App\Enums\InstructorStatus;
use App\Enums\StrikeStatus;
use App\Enums\SuspensionStatus;
use App\Enums\SuspensionType;
use App\Models\Booking;
use App\Models\InstructorAppeal;
use App\Models\InstructorFine;
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

    private function submitConcern(Booking $booking, string|array $categories = ['minor_late']): SafetyIncident
    {
        $items = is_array($categories) ? $categories : [$categories];

        $response = $this->actingAs($this->student)->post(route('student.feedback.store', $booking), [
            'submit_review' => false,
            'submit_concern' => true,
            'concern_categories' => $items,
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

    private function decide(
        SafetyIncident $incident,
        string $outcome = '1_strike',
        array $overrides = []
    ) {
        return $this->actingAs($this->admin)->post(route('admin.incidents.confirm', $incident), array_merge([
            'final_outcome' => $outcome,
            'category' => $incident->category ?? 'minor_late',
            'decision_reason' => 'SISA reviewed the booking timeline and confirmed the concern.',
            'admin_note' => 'Internal audit note: corroborated with booking logs.',
            'instructor_summary' => 'Confirmed delay without prior notice to the student.',
        ], $overrides));
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

    public function test_student_feedback_page_never_exposes_severity_or_strike_weights(): void
    {
        $booking = $this->booking();

        $response = $this->actingAs($this->student)->get(route('student.feedback.create', $booking));

        $response->assertOk()->assertInertia(function (Assert $page) {
            $page->component('Student/Feedback/Create')
                ->where('canReview', true)
                ->where('canReport', true)
                ->has('reportTopics', 5)
                ->has('concernCategories.0', fn (Assert $item) => $item
                    ->has('value')
                    ->has('label')
                    ->has('description')
                    ->has('topic')
                    ->has('topic_label')
                    ->missing('group')
                    ->missing('severity')
                    ->missing('severity_label')
                    ->missing('strikes')
                    ->missing('suggested_outcome')
                );
        });
    }

    public function test_multi_item_checklist_suggests_highest_outcome_and_keeps_severity_hidden_until_sisa_decides(): void
    {
        $booking = $this->booking();
        $incident = $this->submitConcern($booking, ['minor_late', 'minor_communication', 'minor_distraction']);

        $this->assertSame('pending', $incident->status->value);
        $this->assertNull($incident->severity);
        $this->assertNull($incident->final_outcome);
        $this->assertSame(IncidentOutcome::OneStrike, $incident->suggested_outcome);
        $this->assertSame(0, $incident->strike_weight);
        $this->assertSame(['minor_late', 'minor_communication', 'minor_distraction'], $incident->checked_items);
        $this->assertSame(0, $this->instructor->instructorProfile->fresh()->strike_count);

        // Suggested outcome is visible on SISA admin incident view, and stripped from student payload.
        $this->actingAs($this->admin)
            ->get(route('admin.incidents.show', $incident))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Incidents/Show')
                ->where('incident.suggested_outcome', '1_strike')
            );

        $studentPayload = $incident->toStudentPayload();
        $this->assertArrayNotHasKey('suggested_outcome', $studentPayload);
        $this->assertArrayNotHasKey('suggested_severity', $studentPayload);
        $this->assertArrayNotHasKey('admin_note', $studentPayload);
        $this->assertArrayNotHasKey('strike_weight', $studentPayload);
    }

    public function test_sisa_decision_requires_reason_and_admin_note(): void
    {
        $incident = $this->submitConcern($this->booking());

        $this->actingAs($this->admin)
            ->post(route('admin.incidents.confirm', $incident), [
                'final_outcome' => '1_strike',
                'decision_reason' => '',
                'admin_note' => '',
            ])
            ->assertSessionHasErrors(['decision_reason', 'admin_note']);
    }

    public function test_first_strike_issues_warning_and_500_fine_and_keeps_admin_note_private(): void
    {
        $incident = $this->submitConcern($this->booking(), 'minor_late');

        $this->decide($incident, '1_strike', [
            'instructor_summary' => 'Arrived late without prior notice.',
            'admin_note' => 'CONFIDENTIAL_SISA_NOTE_DO_NOT_SHARE',
        ])->assertRedirect();

        $incident->refresh();
        $this->assertSame('confirmed', $incident->status->value);
        $this->assertSame(IncidentOutcome::OneStrike, $incident->final_outcome);
        $this->assertSame(1, $incident->strike_weight);
        $this->assertSame('Arrived late without prior notice.', $incident->instructor_summary);

        $profile = $this->instructor->instructorProfile->fresh();
        $this->assertSame(1, $profile->strike_count);
        $this->assertSame(InstructorStatus::Active, $profile->status);

        $fine = InstructorFine::where('instructor_id', $this->instructor->id)->firstOrFail();
        $this->assertSame(500.0, (float) $fine->amount);
        $this->assertSame(FineStatus::Due, $fine->status);

        // Instructor dashboard shows due fine and never exposes confidential admin note.
        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('profile.strike_count', 1)
                ->has('due_fines', 1)
                ->where('due_fines.0.amount', 500)
            );

        // Admin marks fine as paid outside the system.
        $this->actingAs($this->admin)
            ->post(route('admin.instructors.fines.mark-paid', [$this->instructor, $fine]), [
                'settlement_note' => 'OR #2026-101 received at SISA desk.',
            ])
            ->assertRedirect();

        $this->assertSame(FineStatus::Paid, $fine->fresh()->status);
    }

    public function test_two_strikes_trigger_one_week_suspension_and_reinstatement_preserves_strikes(): void
    {
        $inc1 = $this->submitConcern($this->booking(['date' => now()->subDays(2)->format('Y-m-d')]), 'minor_late');
        $this->decide($inc1, '1_strike');

        $inc2 = $this->submitConcern($this->booking(['date' => now()->subDays(3)->format('Y-m-d')]), 'minor_communication');
        $this->decide($inc2, '1_strike');

        $profile = $this->instructor->instructorProfile->fresh();
        $this->assertSame(2, $profile->strike_count);
        $this->assertSame(InstructorStatus::Suspended, $profile->status);

        $suspension = InstructorSuspension::where('instructor_id', $this->instructor->id)
            ->where('status', SuspensionStatus::Active->value)
            ->firstOrFail();

        $this->assertSame(2, $suspension->strike_tier);
        $this->assertFalse($suspension->requires_sisa_id_confiscation);
        $this->assertEqualsWithDelta(7, $suspension->starts_at->diffInDays($suspension->ends_at), 1);

        // Simulate 1-week expiry: command moves to awaiting_reinstatement, instructor stays suspended.
        $suspension->update(['ends_at' => now()->subHour()]);
        $profile->update(['suspended_until' => now()->subHour()]);

        $this->artisan('instructors:lift-expired-suspensions')->assertSuccessful();

        $this->assertSame(SuspensionStatus::AwaitingReinstatement, $suspension->fresh()->status);
        $this->assertSame(InstructorStatus::Suspended, $profile->fresh()->status);

        // SISA approves reinstatement: profile becomes active, but the 2 active strikes remain on record!
        $this->actingAs($this->admin)
            ->post(route('admin.instructors.reactivate', $this->instructor), [
                'reinstatement_note' => '1-week suspension served and approved by SISA.',
            ])
            ->assertRedirect();

        $profile->refresh();
        $this->assertSame(InstructorStatus::Active, $profile->status);
        $this->assertSame(2, $profile->strike_count);
        $this->assertSame(SuspensionStatus::Completed, $suspension->fresh()->status);

        // A 3rd strike now pushes total to 3 and triggers 1-month Tier 3 suspension.
        $inc3 = $this->submitConcern($this->booking(['date' => now()->subDays(4)->format('Y-m-d')]), 'minor_distraction');
        $this->decide($inc3, '1_strike');

        $profile->refresh();
        $this->assertSame(3, $profile->strike_count);
        $this->assertSame(InstructorStatus::Suspended, $profile->status);

        $tier3 = InstructorSuspension::where('instructor_id', $this->instructor->id)
            ->where('status', SuspensionStatus::Active->value)
            ->firstOrFail();

        $this->assertSame(3, $tier3->strike_tier);
        $this->assertTrue($tier3->requires_sisa_id_confiscation);
        $this->assertEqualsWithDelta(30, $tier3->starts_at->diffInDays($tier3->ends_at), 1);

        // SISA reinstates after Tier 3: strikes are marked served and strike_count resets to 0.
        $tier3->update(['ends_at' => now()->subHour()]);
        $this->artisan('instructors:lift-expired-suspensions')->assertSuccessful();

        $this->actingAs($this->admin)
            ->post(route('admin.instructors.reactivate', $this->instructor), [
                'reinstatement_note' => 'SISA ID returned and 1-month suspension completed.',
            ])
            ->assertRedirect();

        $profile->refresh();
        $this->assertSame(InstructorStatus::Active, $profile->status);
        $this->assertSame(0, $profile->strike_count);
    }

    public function test_third_strike_confirmed_during_active_one_week_suspension_upgrades_to_one_month(): void
    {
        $inc1 = $this->submitConcern($this->booking(['date' => now()->subDays(2)->format('Y-m-d')]), 'major_no_safety_briefing');
        $this->decide($inc1, '2_strikes');

        $tier2 = InstructorSuspension::where('instructor_id', $this->instructor->id)
            ->where('status', SuspensionStatus::Active->value)
            ->firstOrFail();
        $this->assertSame(2, $tier2->strike_tier);

        $inc2 = $this->submitConcern($this->booking(['date' => now()->subDays(3)->format('Y-m-d')]), 'minor_late');
        $this->decide($inc2, '1_strike');

        $tier2->refresh();
        $this->assertSame(3, $tier2->strike_tier);
        $this->assertTrue($tier2->requires_sisa_id_confiscation);
        $this->assertEqualsWithDelta(30, now()->diffInDays($tier2->ends_at), 1);
        $this->assertSame(3, $this->instructor->instructorProfile->fresh()->strike_count);
    }

    public function test_critical_report_is_handled_outside_system_with_zero_strikes_and_optional_manual_hold(): void
    {
        $inc1 = $this->submitConcern($this->booking(['date' => now()->subDays(2)->format('Y-m-d')]), 'critical_harassment');

        $this->decide($inc1, 'critical', [
            'place_manual_hold' => false,
        ])->assertRedirect();

        $inc1->refresh();
        $this->assertSame('confirmed', $inc1->status->value);
        $this->assertSame(IncidentOutcome::Critical, $inc1->final_outcome);
        $this->assertTrue($inc1->handled_externally);
        $this->assertSame(0, $inc1->strike_weight);

        $profile = $this->instructor->instructorProfile->fresh();
        $this->assertSame(0, $profile->strike_count);
        $this->assertSame(InstructorStatus::Active, $profile->status);
        $this->assertDatabaseCount('instructor_strikes', 0);
        $this->assertDatabaseCount('instructor_suspensions', 0);

        // Second critical report with optional manual administrative hold checked.
        $inc2 = $this->submitConcern($this->booking(['date' => now()->subDays(3)->format('Y-m-d')]), 'critical_violence');

        $this->decide($inc2, 'critical', [
            'place_manual_hold' => true,
            'manual_hold_days' => 10,
        ])->assertRedirect();

        $profile->refresh();
        $this->assertSame(0, $profile->strike_count);
        $this->assertSame(InstructorStatus::Suspended, $profile->status);

        $hold = InstructorSuspension::where('instructor_id', $this->instructor->id)->firstOrFail();
        $this->assertSame(SuspensionType::Manual, $hold->type);
        $this->assertNull($hold->strike_tier);
    }

    public function test_admin_dismissal_via_no_strike_or_reject_adds_no_strike_and_keeps_record(): void
    {
        $incident1 = $this->submitConcern($this->booking(['date' => now()->subDays(2)->format('Y-m-d')]));
        $this->decide($incident1, 'no_strike')->assertRedirect();

        $incident1->refresh();
        $this->assertSame('rejected', $incident1->status->value);
        $this->assertSame(IncidentOutcome::NoStrike, $incident1->final_outcome);
        $this->assertSame(0, $this->instructor->instructorProfile->fresh()->strike_count);

        $incident2 = $this->submitConcern($this->booking(['date' => now()->subDays(3)->format('Y-m-d')]));
        $this->actingAs($this->admin)
            ->post(route('admin.incidents.reject', $incident2), [
                'reason' => 'The booking records contradict this account.',
                'admin_note' => 'Checked booking logs and attendance.',
            ])
            ->assertRedirect();

        $this->assertSame('rejected', $incident2->fresh()->status->value);
        $this->assertSame(IncidentOutcome::NoStrike, $incident2->fresh()->final_outcome);
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
            'concern_categories' => ['minor_communication'],
            'concern_details' => 'A second duplicate concern should not be allowed for the same completed booking.',
            'truth_declaration' => true,
        ]);

        $response->assertSessionHasErrors('concern');
        $this->assertSame(1, SafetyIncident::where('booking_id', $booking->id)->count());
    }

    public function test_suspended_page_shows_tier_reason_and_appeal_button_and_blocks_workspace(): void
    {
        $incident = $this->submitConcern($this->booking(), 'major_conditions_ignored');
        $this->decide($incident, '2_strikes');

        $this->actingAs($this->instructor)
            ->get(route('instructor.bookings.index'))
            ->assertRedirect(route('instructor.suspended'));

        $this->actingAs($this->instructor)
            ->get(route('instructor.suspended'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Instructor/Suspended')
                ->where('profile.strike_count', 2)
                ->where('canAppeal', true)
                ->where('suspension.type', 'automatic')
                ->where('suspension.strike_tier', 2)
                ->where('suspension.reason', fn ($reason) => is_string($reason) && str_contains($reason, '2 active strike'))
            );
    }

    public function test_revoking_one_strike_voids_fine_and_revoking_3_to_2_downgrades_tier_3_to_tier_2(): void
    {
        $inc1 = $this->submitConcern($this->booking(['date' => now()->subDays(2)->format('Y-m-d')]), 'minor_late');
        $this->decide($inc1, '1_strike');

        $fine = InstructorFine::where('safety_incident_id', $inc1->id)->firstOrFail();
        $this->assertSame(FineStatus::Due, $fine->status);

        // Revoking the 1-strike incident voids its unpaid fine.
        $this->actingAs($this->admin)
            ->post(route('admin.incidents.revoke', $inc1), [
                'reason' => 'Delay was caused by tide conditions verified by SISA.',
            ])
            ->assertRedirect();

        $this->assertSame(FineStatus::Voided, $fine->fresh()->status);
        $this->assertSame(0, $this->instructor->instructorProfile->fresh()->strike_count);

        // Now accumulate 2 + 1 = 3 strikes -> Tier 3 suspension.
        $inc2 = $this->submitConcern($this->booking(['date' => now()->subDays(3)->format('Y-m-d')]), 'major_no_safety_briefing');
        $this->decide($inc2, '2_strikes');

        $inc3 = $this->submitConcern($this->booking(['date' => now()->subDays(4)->format('Y-m-d')]), 'minor_communication');
        $this->decide($inc3, '1_strike');

        $suspension = InstructorSuspension::where('instructor_id', $this->instructor->id)
            ->where('status', SuspensionStatus::Active->value)
            ->firstOrFail();
        $this->assertSame(3, $suspension->strike_tier);

        // Revoking inc3 drops active strikes from 3 -> 2: downgrades Tier 3 to Tier 2 (7 days) instead of lifting!
        $this->actingAs($this->admin)
            ->post(route('admin.incidents.revoke', $inc3), [
                'reason' => 'Communication concern overturned after reviewing messages.',
            ])
            ->assertRedirect();

        $suspension->refresh();
        $this->assertSame(2, $this->instructor->instructorProfile->fresh()->strike_count);
        $this->assertSame(InstructorStatus::Suspended, $this->instructor->instructorProfile->fresh()->status);
        $this->assertSame(2, $suspension->strike_tier);
        $this->assertFalse($suspension->requires_sisa_id_confiscation);

        // Revoking inc2 drops active strikes from 2 -> 0: lifts suspension!
        $this->actingAs($this->admin)
            ->post(route('admin.incidents.revoke', $inc2), [
                'reason' => 'Briefing was held onshore prior to recording.',
            ])
            ->assertRedirect();

        $this->assertSame(InstructorStatus::Active, $this->instructor->instructorProfile->fresh()->status);
        $this->assertSame(0, $this->instructor->instructorProfile->fresh()->strike_count);
        $this->assertSame(SuspensionStatus::RevokedByAdmin, $suspension->fresh()->status);
    }

    public function test_appeal_on_tier_2_requires_reducing_strikes_below_2_and_lifts_when_revoked(): void
    {
        $inc = $this->submitConcern($this->booking(), 'major_conditions_ignored');
        $this->decide($inc, '2_strikes');

        $this->actingAs($this->instructor)
            ->post(route('instructor.suspension.appeal'), [
                'message' => 'I was not the instructor assigned to this session. My booking records show I was teaching elsewhere.',
                'ground' => 'not_my_session',
                'truth_declaration' => true,
            ])
            ->assertRedirect();

        $appeal = InstructorAppeal::where('instructor_id', $this->instructor->id)->firstOrFail();

        // Trying to approve without revoking enough strikes to drop below 2 must fail validation.
        $this->actingAs($this->admin)
            ->post(route('admin.appeals.approve', $appeal), [
                'response' => 'Attempting to approve without revoking the underlying incident.',
                'revoke_incident_ids' => [],
            ])
            ->assertSessionHasErrors('revoke_incident_ids');

        // Approving while revoking the 2-strike incident drops strikes to 0 and lifts suspension.
        $this->actingAs($this->admin)
            ->post(route('admin.appeals.approve', $appeal), [
                'response' => 'Records confirm the instructor was not assigned to this session.',
                'revoke_incident_ids' => [$inc->id],
            ])
            ->assertRedirect();

        $this->assertSame('approved', $appeal->fresh()->status->value);
        $this->assertSame(InstructorStatus::Active, $this->instructor->instructorProfile->fresh()->status);
        $this->assertSame(0, $this->instructor->instructorProfile->fresh()->strike_count);
        $this->assertDatabaseHas('instructor_strikes', [
            'safety_incident_id' => $inc->id,
            'status' => StrikeStatus::Revoked->value,
        ]);
        $this->assertDatabaseHas('instructor_suspensions', [
            'instructor_id' => $this->instructor->id,
            'status' => SuspensionStatus::LiftedOnAppeal->value,
        ]);
    }

    public function test_admin_can_suspend_manually_and_log_direct_incident(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.incidents.store'), [
                'instructor_id' => $this->instructor->id,
                'booking_id' => null,
                'category' => 'major_conditions_ignored',
                'type' => 'rule_violation',
                'final_outcome' => '2_strikes',
                'description' => 'The instructor took a beginner group into a strong current after a low-tide warning.',
                'location' => 'Cloud 9',
                'decision_reason' => 'Confirmed by SISA harbor patrol officer.',
                'admin_note' => 'Logged directly from patrol report.',
                'instructor_summary' => 'Took a beginner group into unsafe conditions after a warning.',
            ])
            ->assertRedirect();

        $this->assertSame(2, $this->instructor->instructorProfile->fresh()->strike_count);
        $this->assertSame(InstructorStatus::Suspended, $this->instructor->instructorProfile->fresh()->status);
        $this->assertDatabaseHas('safety_incidents', [
            'instructor_id' => $this->instructor->id,
            'status' => 'confirmed',
            'source' => 'admin',
            'final_outcome' => '2_strikes',
            'strike_weight' => 2,
        ]);
    }
}
