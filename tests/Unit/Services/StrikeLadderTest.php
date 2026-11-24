<?php

namespace Tests\Unit\Services;

use App\Enums\FeedbackCategory;
use App\Enums\FineStatus;
use App\Enums\IncidentOutcome;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\InstructorLevel;
use App\Enums\InstructorStatus;
use App\Enums\SuspensionStatus;
use App\Models\InstructorFine;
use App\Models\InstructorSuspension;
use App\Models\SafetyIncident;
use App\Models\User;
use App\Services\StrikeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StrikeLadderTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');

        $this->instructor = User::factory()->instructor()->create();
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => 1000,
            'strike_count' => 0,
        ]);

        $this->admin = User::factory()->admin()->create();
        $this->admin->assignRole('admin');
    }

    private function createConfirmedIncident(IncidentOutcome $outcome, string $category = 'minor_late'): SafetyIncident
    {
        $isCritical = $outcome->isCritical();

        return SafetyIncident::create([
            'instructor_id' => $this->instructor->id,
            'reported_by' => $this->admin->id,
            'source' => IncidentSource::Admin,
            'status' => IncidentStatus::Confirmed,
            'category' => $category,
            'type' => IncidentType::RuleViolation,
            'severity' => $outcome->severity(),
            'suggested_outcome' => $outcome,
            'final_outcome' => $outcome,
            'strike_weight' => $outcome->strikes(),
            'handled_externally' => $isCritical,
            'external_handled_at' => $isCritical ? now() : null,
            'description' => 'Confirmed safety incident for strike ladder testing.',
            'decision_reason' => 'Confirmed during SISA review.',
            'admin_note' => 'Internal SISA note.',
            'confirmed_at' => now(),
            'confirmed_by' => $this->admin->id,
        ]);
    }

    public function test_tier_1_one_strike_issues_warning_and_500_fine_without_suspension(): void
    {
        $incident = $this->createConfirmedIncident(IncidentOutcome::OneStrike);

        app(StrikeService::class)->applyForIncident($incident, $this->admin);

        $profile = $this->instructor->instructorProfile->fresh();
        $this->assertSame(1, $profile->strike_count);
        $this->assertSame(InstructorStatus::Active, $profile->status);
        $this->assertDatabaseCount('instructor_suspensions', 0);

        $fine = InstructorFine::where('instructor_id', $this->instructor->id)->firstOrFail();
        $this->assertSame(500.0, (float) $fine->amount);
        $this->assertSame(FineStatus::Due, $fine->status);
        $this->assertSame($incident->id, $fine->safety_incident_id);
    }

    public function test_tier_2_two_strikes_triggers_one_week_suspension(): void
    {
        $first = $this->createConfirmedIncident(IncidentOutcome::OneStrike, 'minor_late');
        app(StrikeService::class)->applyForIncident($first, $this->admin);

        $second = $this->createConfirmedIncident(IncidentOutcome::OneStrike, 'minor_communication');
        app(StrikeService::class)->applyForIncident($second, $this->admin);

        $profile = $this->instructor->instructorProfile->fresh();
        $this->assertSame(2, $profile->strike_count);
        $this->assertSame(InstructorStatus::Suspended, $profile->status);

        $suspension = InstructorSuspension::where('instructor_id', $this->instructor->id)
            ->where('status', SuspensionStatus::Active->value)
            ->firstOrFail();

        $this->assertSame(2, $suspension->strike_tier);
        $this->assertFalse($suspension->requires_sisa_id_confiscation);
        $this->assertEqualsWithDelta(7, $suspension->starts_at->diffInDays($suspension->ends_at), 1);
    }

    public function test_direct_jump_0_to_2_applies_only_one_week_suspension_and_no_fine(): void
    {
        $incident = $this->createConfirmedIncident(IncidentOutcome::TwoStrikes, 'major_no_safety_briefing');
        app(StrikeService::class)->applyForIncident($incident, $this->admin);

        $profile = $this->instructor->instructorProfile->fresh();
        $this->assertSame(2, $profile->strike_count);
        $this->assertSame(InstructorStatus::Suspended, $profile->status);
        $this->assertDatabaseCount('instructor_fines', 0);

        $suspension = InstructorSuspension::where('instructor_id', $this->instructor->id)->firstOrFail();
        $this->assertSame(2, $suspension->strike_tier);
        $this->assertEqualsWithDelta(7, $suspension->starts_at->diffInDays($suspension->ends_at), 1);
    }

    public function test_tier_3_three_strikes_triggers_one_month_suspension_with_id_confiscation_flag(): void
    {
        $incident = $this->createConfirmedIncident(IncidentOutcome::ThreeStrikes, 'major_conditions_ignored');
        app(StrikeService::class)->applyForIncident($incident, $this->admin);

        $profile = $this->instructor->instructorProfile->fresh();
        $this->assertSame(3, $profile->strike_count);
        $this->assertSame(InstructorStatus::Suspended, $profile->status);
        $this->assertDatabaseCount('instructor_fines', 0);

        $suspension = InstructorSuspension::where('instructor_id', $this->instructor->id)->firstOrFail();
        $this->assertSame(3, $suspension->strike_tier);
        $this->assertTrue($suspension->requires_sisa_id_confiscation);
        $this->assertEqualsWithDelta(30, $suspension->starts_at->diffInDays($suspension->ends_at), 1);
    }

    public function test_critical_severity_carries_zero_strikes_and_does_not_auto_suspend(): void
    {
        $this->assertSame(0, IncidentSeverity::Critical->strikes());

        $incident = $this->createConfirmedIncident(IncidentOutcome::Critical, 'critical_harassment');
        $strike = app(StrikeService::class)->applyForIncident($incident, $this->admin);

        $this->assertNull($strike);
        $profile = $this->instructor->instructorProfile->fresh();
        $this->assertSame(0, $profile->strike_count);
        $this->assertSame(InstructorStatus::Active, $profile->status);
        $this->assertDatabaseCount('instructor_strikes', 0);
        $this->assertDatabaseCount('instructor_suspensions', 0);
    }

    public function test_multi_item_checklist_suggests_highest_outcome_without_summing(): void
    {
        // Checking three minor (1-strike) items must suggest 1_strike, never 3_strikes.
        $outcome = FeedbackCategory::suggestedOutcomeForCategories([
            FeedbackCategory::MinorLate->value,
            FeedbackCategory::MinorCommunication->value,
            FeedbackCategory::MinorDistraction->value,
        ]);

        $this->assertSame(IncidentOutcome::OneStrike, $outcome);

        // Checking a minor + critical item must suggest Critical.
        $escalated = FeedbackCategory::suggestedOutcomeForCategories([
            FeedbackCategory::MinorLate->value,
            FeedbackCategory::CriticalDangerousSpot->value,
        ]);

        $this->assertSame(IncidentOutcome::Critical, $escalated);
    }

    public function test_student_concern_options_hide_severity_and_strike_metadata(): void
    {
        $options = FeedbackCategory::studentConcernOptions();

        $this->assertNotEmpty($options);

        foreach ($options as $option) {
            $this->assertArrayNotHasKey('group', $option);
            $this->assertArrayNotHasKey('severity', $option);
            $this->assertArrayNotHasKey('severity_label', $option);
            $this->assertArrayNotHasKey('strikes', $option);
            $this->assertArrayNotHasKey('suggested_outcome', $option);
            $this->assertNotContains($option['value'], [
                FeedbackCategory::MinorOther->value,
                FeedbackCategory::MajorOther->value,
                FeedbackCategory::CriticalOther->value,
            ]);
        }
    }
}
