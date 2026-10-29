<?php

namespace Database\Seeders;

use App\Enums\AppealGround;
use App\Enums\AppealStatus;
use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DayOfWeek;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\InstructorLevel;
use App\Enums\InstructorStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SkillLevel;
use App\Enums\StrikeStatus;
use App\Enums\SuspensionStatus;
use App\Enums\SuspensionType;
use App\Enums\TimePeriod;
use App\Enums\WaiverType;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\Certificate;
use App\Models\InstructorAppeal;
use App\Models\InstructorProfile;
use App\Models\InstructorStrike;
use App\Models\InstructorSuspension;
use App\Models\Payment;
use App\Models\Review;
use App\Models\SafetyIncident;
use App\Models\SkillUpgradeRequest;
use App\Models\StudentProfile;
use App\Models\SurfSpot;
use App\Models\User;
use App\Models\Waiver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data used only for UI screenshots.
 */
class ScreenshotDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SurfSpotSeeder::class,
        ]);

        $password = Hash::make('password');
        $now = now();

        $admin = $this->user('SISA Admin', 'admin@siasurf.test', 'admin', $password);

        $coachMarlon = $this->instructor('Marlon Daku', 'coach.marlon@siasurf.test', $password, [
            'bio' => 'Siargao local with 12 years coaching beginners at Cloud 9 and Jacking Horse. Patient, safety-first, and happiest in glassy morning sessions.',
            'level' => InstructorLevel::Level2,
            'status' => InstructorStatus::Active,
            'rate_per_hour' => 700,
            'area' => 'General Luna',
            'meeting_point' => 'Cloud 9 tower',
            'latitude' => 9.8119,
            'longitude' => 126.1672,
        ]);

        $coachLiza = $this->instructor('Liza Reyes', 'coach.liza@siasurf.test', $password, [
            'bio' => 'ISA-certified surf coach focused on first-timers and kids. Ocean safety, tide reading, and confidence building are my specialty.',
            'level' => InstructorLevel::Level1,
            'status' => InstructorStatus::Active,
            'rate_per_hour' => 600,
            'area' => 'General Luna',
            'meeting_point' => 'Jacking Horse beach',
            'latitude' => 9.8003,
            'longitude' => 126.1642,
        ]);

        $coachBen = $this->instructor('Ben Salazar', 'coach.ben@siasurf.test', $password, [
            'bio' => 'Advanced shortboard coach. WSL regional competitor, cloud 9 specialist, video analysis included.',
            'level' => InstructorLevel::Level3,
            'status' => InstructorStatus::Active,
            'rate_per_hour' => 1800,
            'area' => 'General Luna',
            'meeting_point' => 'Cloud 9 main break',
            'latitude' => 9.8089,
            'longitude' => 126.1525,
        ]);

        $coachPending = $this->instructor('Paolo Cruz', 'coach.pending@siasurf.test', $password, [
            'bio' => 'Freshly returned from Bali. Applying to join the SISA roster for the coming season.',
            'level' => InstructorLevel::Level1,
            'status' => InstructorStatus::PendingVerification,
            'rate_per_hour' => 600,
        ]);

        $coachSuspended = $this->instructor('Rico Alvarez', 'coach.suspended@siasurf.test', $password, [
            'bio' => 'Surf coach with 6 years experience around General Luna.',
            'level' => InstructorLevel::Level2,
            'status' => InstructorStatus::Suspended,
            'rate_per_hour' => 900,
            'strike_count' => 3,
            'suspension_reason' => 'Three confirmed safety violations within one season, including exceeding the certified student ratio.',
            'suspended_at' => $now->copy()->subDays(6),
            'suspended_until' => $now->copy()->addDays(24),
        ]);

        $coachAppealing = $this->instructor('Nina Torres', 'coach.appealing@siasurf.test', $password, [
            'bio' => 'Long-time instructor at San Isidro. Currently under review after a student report.',
            'level' => InstructorLevel::Level1,
            'status' => InstructorStatus::Suspended,
            'rate_per_hour' => 650,
            'strike_count' => 2,
            'suspension_reason' => 'Manual suspension pending SISA review of a student report about a missed safety briefing.',
            'suspended_at' => $now->copy()->subDays(2),
            'suspended_until' => $now->copy()->addDays(28),
        ]);

        $student = $this->user('Ana Reyes', 'student@siasurf.test', 'student', $password);
        $student->assignRole('student');
        StudentProfile::create([
            'user_id' => $student->id,
            'skill_level' => SkillLevel::Beginner->value,
            'is_first_time' => false,
            'status' => 'active',
        ]);

        $student2 = $this->user('Miguel Santos', 'student2@siasurf.test', 'student', $password);
        $student2->assignRole('student');
        StudentProfile::create([
            'user_id' => $student2->id,
            'skill_level' => SkillLevel::Intermediate->value,
            'is_first_time' => true,
            'status' => 'active',
        ]);

        // Certificates
        $this->certificates($coachMarlon, [
            [CertificateType::BLS, CertificateStatus::Verified, $now->copy()->addMonths(10)],
            [CertificateType::ISA, CertificateStatus::Verified, $now->copy()->addMonths(2)],
        ]);
        $this->certificates($coachLiza, [
            [CertificateType::BLS, CertificateStatus::Verified, $now->copy()->addMonths(8)],
            [CertificateType::SISA, CertificateStatus::Verified, $now->copy()->addDays(20)],
        ]);
        $this->certificates($coachBen, [
            [CertificateType::ISA, CertificateStatus::Verified, $now->copy()->addMonths(14)],
            [CertificateType::SurfSkill, CertificateStatus::Verified, $now->copy()->addMonths(6)],
        ]);
        $this->certificates($coachPending, [
            [CertificateType::BLS, CertificateStatus::PendingVerification, null],
            [CertificateType::SurfSkill, CertificateStatus::PendingVerification, null],
        ]);
        $this->certificates($coachSuspended, [
            [CertificateType::BLS, CertificateStatus::Verified, $now->copy()->addMonths(4)],
        ]);
        $this->certificates($coachAppealing, [
            [CertificateType::ISA, CertificateStatus::Verified, $now->copy()->addMonths(9)],
        ]);

        $cloud9 = SurfSpot::where('name', 'Cloud 9 (Outer)')->first()
            ?? SurfSpot::where('name', 'like', 'Cloud 9%')->first();
        $jacking = SurfSpot::where('name', 'Jacking Horse (Outer)')->first()
            ?? SurfSpot::where('name', 'like', 'Jacking Horse%')->first()
            ?? SurfSpot::where('is_safe_zone', true)->first();
        $safeSpot = SurfSpot::where('is_safe_zone', true)->orderBy('sort_order')->first() ?? $cloud9;

        // Weekly availability for active coaches
        foreach ([$coachMarlon, $coachLiza, $coachBen] as $coach) {
            foreach ([DayOfWeek::Monday, DayOfWeek::Wednesday, DayOfWeek::Friday, DayOfWeek::Saturday] as $day) {
                foreach ([TimePeriod::Morning, TimePeriod::Afternoon] as $period) {
                    Availability::create([
                        'user_id' => $coach->id,
                        'day_of_week' => $day,
                        'time_period' => $period,
                        'is_available' => true,
                    ]);
                }
            }
        }

        // 1. Completed + paid + reviewed lesson
        $b1 = $this->booking($student, $coachMarlon, $safeSpot, [
            'date' => $now->copy()->subDays(3)->toDateString(),
            'time_period' => TimePeriod::Morning,
            'skill_level' => SkillLevel::Beginner,
            'student_age' => 24,
            'student_count' => 2,
            'status' => BookingStatus::Completed,
            'total_amount' => 1400,
            'payment_status' => PaymentStatus::Completed,
            'has_board' => true,
            'completed_at' => $now->copy()->subDays(3)->setTime(10, 0),
            'attendance_status' => AttendanceStatus::Present,
            'notes' => 'First time on a board, very nervous about the reef.',
        ]);
        $this->payment($b1, PaymentMethod::GCash, PaymentStatus::Completed, 'pay_3Nq8demo1');
        $this->waiver($b1, $student);
        $this->review($b1, $student, $coachMarlon, 5, 'Marlon was incredible with my nerves. Clear safety briefing, patient coaching on the white water, and I stood up on my third wave. Highly recommend for first-timers!', [
            'positive_coaching',
            'positive_safety',
        ], 'Thanks Ana! You did the hard part — see you next season.');

        // 2. Completed + paid, no review yet (feedback hub)
        $b2 = $this->booking($student, $coachLiza, $safeSpot, [
            'date' => $now->copy()->subDays(1)->toDateString(),
            'time_period' => TimePeriod::Afternoon,
            'skill_level' => SkillLevel::Beginner,
            'student_age' => 24,
            'student_count' => 1,
            'status' => BookingStatus::Completed,
            'total_amount' => 1200,
            'payment_status' => PaymentStatus::Completed,
            'has_board' => false,
            'completed_at' => $now->copy()->subDay()->setTime(16, 0),
            'attendance_status' => AttendanceStatus::Present,
        ]);
        $this->payment($b2, PaymentMethod::Cash, PaymentStatus::Completed, null);
        $this->waiver($b2, $student);

        // 3. Upcoming confirmed lesson
        $b3 = $this->booking($student, $coachBen, $cloud9, [
            'date' => $now->copy()->addDays(2)->toDateString(),
            'time_period' => TimePeriod::Morning,
            'skill_level' => SkillLevel::Advanced,
            'student_age' => 24,
            'student_count' => 1,
            'status' => BookingStatus::Confirmed,
            'total_amount' => 3600,
            'payment_status' => PaymentStatus::Completed,
            'has_board' => true,
            'accepted_at' => $now->copy()->subHours(20),
            'notes' => 'Working on cutbacks and barrel positioning.',
        ]);
        $this->payment($b3, PaymentMethod::GCash, PaymentStatus::Completed, 'pay_3Nq8demo2');
        $this->waiver($b3, $student);

        // 4. Awaiting payment (payment page)
        $b4 = $this->booking($student, $coachMarlon, $jacking, [
            'date' => $now->copy()->addDays(4)->toDateString(),
            'time_period' => TimePeriod::Afternoon,
            'skill_level' => SkillLevel::Intermediate,
            'student_age' => 24,
            'student_count' => 1,
            'status' => BookingStatus::AwaitingPayment,
            'total_amount' => 1400,
            'payment_status' => PaymentStatus::Pending,
            'has_board' => false,
            'accepted_at' => $now->copy()->subMinutes(5),
            'payment_deadline_at' => $now->copy()->addMinutes(10),
        ]);
        $this->waiver($b4, $student);
        Payment::create([
            'booking_id' => $b4->id,
            'amount' => 1400,
            'payment_method' => PaymentMethod::GCash,
            'status' => PaymentStatus::Pending,
        ]);

        // 5. Pending request waiting on the instructor
        $b5 = $this->booking($student2, $coachLiza, $safeSpot, [
            'date' => $now->copy()->addDays(5)->toDateString(),
            'time_period' => TimePeriod::Morning,
            'skill_level' => SkillLevel::Beginner,
            'student_age' => 19,
            'student_count' => 2,
            'status' => BookingStatus::Pending,
            'total_amount' => 1200,
            'payment_status' => PaymentStatus::Pending,
            'has_board' => true,
            'accept_deadline_at' => $now->copy()->addHours(1),
        ]);

        // 6. Live session right now
        $b6 = $this->booking($student2, $coachMarlon, $safeSpot, [
            'date' => $now->copy()->toDateString(),
            'time_period' => TimePeriod::Morning,
            'skill_level' => SkillLevel::Beginner,
            'student_age' => 19,
            'student_count' => 1,
            'status' => BookingStatus::InProgress,
            'total_amount' => 1400,
            'payment_status' => PaymentStatus::Completed,
            'has_board' => false,
            'started_at' => $now->copy()->subMinutes(35),
            'accepted_at' => $now->copy()->subDay(),
            'attendance_status' => AttendanceStatus::Present,
        ]);
        $this->payment($b6, PaymentMethod::GCash, PaymentStatus::Completed, 'pay_3Nq8demo3');
        $this->waiver($b6, $student2);

        // 7. Cancelled lesson
        $b7 = $this->booking($student2, $coachBen, $cloud9, [
            'date' => $now->copy()->subDays(6)->toDateString(),
            'time_period' => TimePeriod::Morning,
            'skill_level' => SkillLevel::Advanced,
            'student_age' => 19,
            'student_count' => 1,
            'status' => BookingStatus::Cancelled,
            'total_amount' => 3600,
            'payment_status' => PaymentStatus::Refunded,
            'has_board' => true,
            'cancelled_at' => $now->copy()->subDays(7),
            'cancellation_reason' => 'weather',
            'notes' => 'Cancelled because of the typhoon swell warning.',
        ]);

        // 8. Confirmed but waiver still unsigned
        $b8 = $this->booking($student, $coachLiza, $safeSpot, [
            'date' => $now->copy()->addDays(6)->toDateString(),
            'time_period' => TimePeriod::Morning,
            'skill_level' => SkillLevel::Beginner,
            'student_age' => 24,
            'student_count' => 1,
            'status' => BookingStatus::Confirmed,
            'total_amount' => 1200,
            'payment_status' => PaymentStatus::PendingCash,
            'has_board' => true,
        ]);

        // 9. Minor: needs parental consent
        $b9 = $this->booking($student2, $coachLiza, $safeSpot, [
            'date' => $now->copy()->addDays(7)->toDateString(),
            'time_period' => TimePeriod::Afternoon,
            'skill_level' => SkillLevel::Beginner,
            'student_age' => 10,
            'student_count' => 1,
            'status' => BookingStatus::Confirmed,
            'total_amount' => 1200,
            'payment_status' => PaymentStatus::PendingCash,
            'has_board' => false,
        ]);

        // 10. Second completed lesson with review for profile variety
        $b10 = $this->booking($student2, $coachMarlon, $safeSpot, [
            'date' => $now->copy()->subDays(12)->toDateString(),
            'time_period' => TimePeriod::Morning,
            'skill_level' => SkillLevel::Intermediate,
            'student_age' => 19,
            'student_count' => 1,
            'status' => BookingStatus::Completed,
            'total_amount' => 1400,
            'payment_status' => PaymentStatus::Completed,
            'has_board' => true,
            'completed_at' => $now->copy()->subDays(12)->setTime(10, 0),
            'attendance_status' => AttendanceStatus::Late,
        ]);
        $this->payment($b10, PaymentMethod::GCash, PaymentStatus::Completed, 'pay_3Nq8demo4');
        $this->waiver($b10, $student2);
        $this->review($b10, $student2, $coachMarlon, 4, 'Solid lesson and good local knowledge about the tides. We lost a bit of time at the start but the coaching was worth it.', [
            'positive_local_knowledge',
        ], null);

        // Change request awaiting SISA approval
        BookingChangeRequest::create([
            'booking_id' => $b3->id,
            'requested_by' => $coachBen->id,
            'type' => 'reschedule',
            'status' => 'pending',
            'requires_admin_approval' => true,
            'reason' => 'Competition heat scheduled the same morning — requesting a move to the afternoon block.',
            'original_date' => $b3->date,
            'original_time_period' => TimePeriod::Morning->value,
            'original_start_time' => '08:00:00',
            'original_end_time' => '10:00:00',
            'new_date' => $now->copy()->addDays(3)->toDateString(),
            'new_time_period' => TimePeriod::Afternoon->value,
            'new_start_time' => '14:00:00',
            'new_end_time' => '16:00:00',
        ]);

        // Skill upgrade request
        SkillUpgradeRequest::create([
            'student_id' => $student->id,
            'instructor_id' => $coachMarlon->id,
            'booking_id' => $b1->id,
            'current_level' => SkillLevel::Beginner->value,
            'requested_level' => SkillLevel::Intermediate->value,
            'status' => 'pending',
        ]);

        // --- Feedback / incidents -------------------------------------------
        // Pending student concern (minor) on booking 2
        $incident1 = SafetyIncident::create([
            'booking_id' => $b2->id,
            'instructor_id' => $coachLiza->id,
            'reported_by' => $student->id,
            'source' => IncidentSource::StudentFeedback,
            'status' => IncidentStatus::Pending,
            'category' => 'minor_late',
            'type' => IncidentType::RuleViolation,
            'severity' => IncidentSeverity::Minor,
            'suggested_severity' => IncidentSeverity::Minor,
            'strike_weight' => 0,
            'description' => 'The lesson started about 25 minutes late and finished early because the instructor had to leave for another booking. I still paid for the full two hours.',
            'instructor_summary' => null,
            'location' => 'General Luna',
        ]);

        // Confirmed major incident against the suspended coach
        $incident2 = SafetyIncident::create([
            'booking_id' => $b1->id,
            'instructor_id' => $coachSuspended->id,
            'reported_by' => $admin->id,
            'source' => IncidentSource::Admin,
            'status' => IncidentStatus::Confirmed,
            'category' => 'major_ratio_exceeded',
            'type' => IncidentType::RuleViolation,
            'severity' => IncidentSeverity::Major,
            'suggested_severity' => IncidentSeverity::Major,
            'strike_weight' => 2,
            'description' => 'Instructor ran a beginner lesson with five students against a Level 2 one-on-one certification. Observed by a SISA marshall on the beach.',
            'instructor_summary' => 'Exceeded your certified one-on-one ratio with five beginner students in the water.',
            'location' => 'Cloud 9',
            'confirmed_at' => $now->copy()->subDays(8),
            'confirmed_by' => $admin->id,
            'strike_applied_at' => $now->copy()->subDays(8),
        ]);

        $incident3 = SafetyIncident::create([
            'booking_id' => null,
            'instructor_id' => $coachSuspended->id,
            'reported_by' => $admin->id,
            'source' => IncidentSource::Admin,
            'status' => IncidentStatus::Confirmed,
            'category' => 'minor_late',
            'type' => IncidentType::RuleViolation,
            'severity' => IncidentSeverity::Minor,
            'suggested_severity' => IncidentSeverity::Minor,
            'strike_weight' => 1,
            'description' => 'Repeated late starts reported by two students on separate mornings.',
            'instructor_summary' => 'Repeated late lesson starts without notice.',
            'location' => 'General Luna',
            'confirmed_at' => $now->copy()->subDays(7),
            'confirmed_by' => $admin->id,
            'strike_applied_at' => $now->copy()->subDays(7),
        ]);

        // Rejected report
        SafetyIncident::create([
            'booking_id' => $b10->id,
            'instructor_id' => $coachMarlon->id,
            'reported_by' => $student2->id,
            'source' => IncidentSource::StudentFeedback,
            'status' => IncidentStatus::Rejected,
            'category' => 'minor_equipment',
            'type' => IncidentType::RuleViolation,
            'severity' => IncidentSeverity::Minor,
            'suggested_severity' => IncidentSeverity::Minor,
            'strike_weight' => 0,
            'description' => 'The rental board felt too small for my weight.',
            'rejected_at' => $now->copy()->subDays(10),
            'rejected_by' => $admin->id,
            'rejection_reason' => 'Equipment was checked before the lesson and matched the student height/weight chart. No safety issue found.',
            'resolved_at' => $now->copy()->subDays(10),
        ]);

        // Revoked confirmed incident
        $incident4 = SafetyIncident::create([
            'booking_id' => null,
            'instructor_id' => $coachBen->id,
            'reported_by' => $admin->id,
            'source' => IncidentSource::Admin,
            'status' => IncidentStatus::Revoked,
            'category' => 'minor_communication',
            'type' => IncidentType::RuleViolation,
            'severity' => IncidentSeverity::Minor,
            'suggested_severity' => IncidentSeverity::Minor,
            'strike_weight' => 1,
            'description' => 'Student reported unclear instructions before the session.',
            'instructor_summary' => 'Unclear pre-session communication.',
            'confirmed_at' => $now->copy()->subDays(20),
            'confirmed_by' => $admin->id,
            'strike_applied_at' => $now->copy()->subDays(20),
            'resolved_at' => $now->copy()->subDays(15),
        ]);

        // Strikes for the suspended coach
        $strikeA = InstructorStrike::create([
            'instructor_id' => $coachSuspended->id,
            'safety_incident_id' => $incident2->id,
            'weight' => 2,
            'reason_code' => 'major_ratio_exceeded',
            'reason_text' => 'Exceeded certified student ratio.',
            'source' => IncidentSource::Admin,
            'status' => StrikeStatus::Active,
            'applied_by' => $admin->id,
            'applied_at' => $now->copy()->subDays(8),
        ]);

        InstructorStrike::create([
            'instructor_id' => $coachSuspended->id,
            'safety_incident_id' => $incident3->id,
            'weight' => 1,
            'reason_code' => 'minor_late',
            'reason_text' => 'Repeated late lesson starts.',
            'source' => IncidentSource::Admin,
            'status' => StrikeStatus::Active,
            'applied_by' => $admin->id,
            'applied_at' => $now->copy()->subDays(7),
        ]);

        InstructorSuspension::create([
            'instructor_id' => $coachSuspended->id,
            'type' => SuspensionType::Automatic,
            'status' => SuspensionStatus::Active,
            'reason' => 'Three active confirmed strikes triggered an automatic 30-day suspension.',
            'starts_at' => $now->copy()->subDays(6),
            'ends_at' => $now->copy()->addDays(24),
            'suspended_by' => $admin->id,
            'triggering_incident_id' => $incident3->id,
        ]);

        // Second suspended coach with a pending appeal
        $suspensionB = InstructorSuspension::create([
            'instructor_id' => $coachAppealing->id,
            'type' => SuspensionType::Manual,
            'status' => SuspensionStatus::Active,
            'reason' => 'Manual suspension pending SISA review of a student report about a missed safety briefing.',
            'starts_at' => $now->copy()->subDays(2),
            'ends_at' => $now->copy()->addDays(28),
            'suspended_by' => $admin->id,
        ]);

        InstructorAppeal::create([
            'instructor_id' => $coachAppealing->id,
            'instructor_suspension_id' => $suspensionB->id,
            'message' => 'I ran the full safety briefing on the beach before entering the water. Two students and the barangay boatman can confirm it. The report likely refers to a different group.',
            'ground' => AppealGround::FactuallyIncorrect,
            'status' => AppealStatus::Pending,
        ]);

        // Approved appeal on a completed suspension for the archive view
        $suspensionC = InstructorSuspension::create([
            'instructor_id' => $coachBen->id,
            'type' => SuspensionType::Automatic,
            'status' => SuspensionStatus::LiftedOnAppeal,
            'reason' => 'Two minor strikes briefly reached the suspension threshold.',
            'starts_at' => $now->copy()->subDays(18),
            'ends_at' => $now->copy()->addDays(12),
            'suspended_by' => $admin->id,
            'triggering_incident_id' => $incident4->id,
        ]);

        InstructorAppeal::create([
            'instructor_id' => $coachBen->id,
            'instructor_suspension_id' => $suspensionC->id,
            'message' => 'The reported incident was a miscommunication about the meeting point. The student confirmed this in writing afterwards.',
            'ground' => AppealGround::NewEvidence,
            'status' => AppealStatus::Approved,
            'admin_response' => 'Reviewed the written statement from the student and revoked the incident. Suspension lifted and the strike removed from the record.',
            'reviewed_by' => $admin->id,
            'reviewed_at' => $now->copy()->subDays(14),
            'revoked_incident_ids' => [$incident4->id],
        ]);

        InstructorStrike::create([
            'instructor_id' => $coachBen->id,
            'safety_incident_id' => $incident4->id,
            'weight' => 1,
            'reason_code' => 'minor_communication',
            'reason_text' => 'Unclear pre-session communication.',
            'source' => IncidentSource::Admin,
            'status' => StrikeStatus::Revoked,
            'applied_by' => $admin->id,
            'applied_at' => $now->copy()->subDays(20),
            'revoked_by' => $admin->id,
            'revoked_at' => $now->copy()->subDays(14),
            'revocation_reason' => 'Incident revoked after appeal approval.',
        ]);

        // Keep references alive for clarity
        unset($strikeA, $incident1, $safeSpot);
    }

    private function user(string $name, string $email, string $role, string $password): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    private function instructor(string $name, string $email, string $password, array $profile): User
    {
        $user = $this->user($name, $email, 'instructor', $password);
        $user->assignRole('instructor');

        InstructorProfile::create(array_merge([
            'user_id' => $user->id,
            'bio' => null,
            'level' => InstructorLevel::Level1,
            'status' => InstructorStatus::Active,
            'rate_per_hour' => 600,
            'strike_count' => 0,
            'auto_accept_bookings' => false,
        ], $profile));

        return $user;
    }

    private function certificates(User $instructor, array $rows): void
    {
        foreach ($rows as [$type, $status, $expiresAt]) {
            Certificate::create([
                'user_id' => $instructor->id,
                'type' => $type,
                'file_path' => 'certificates/demo-'.strtolower($type->value).'-'.$instructor->id.'.pdf',
                'status' => $status,
                'verified_at' => $status === CertificateStatus::Verified ? now()->subMonths(2) : null,
                'expires_at' => $expiresAt,
                'admin_notes' => null,
            ]);
        }
    }

    private function booking(User $student, User $instructor, ?SurfSpot $spot, array $data): Booking
    {
        return Booking::create(array_merge([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'surf_spot_id' => $spot?->id,
            'date' => now()->addDays(3)->toDateString(),
            'time_period' => TimePeriod::Morning,
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'duration_hours' => 2,
            'skill_level' => SkillLevel::Beginner,
            'student_count' => 1,
            'student_age' => 24,
            'total_amount' => 1200,
            'payment_status' => PaymentStatus::Pending,
            'has_board' => true,
        ], $data));
    }

    private function payment(Booking $booking, PaymentMethod $method, PaymentStatus $status, ?string $transactionId): void
    {
        Payment::create([
            'booking_id' => $booking->id,
            'amount' => $booking->total_amount,
            'payment_method' => $method,
            'transaction_id' => $transactionId,
            'status' => $status,
            'paid_at' => $status === PaymentStatus::Completed ? now()->subDay() : null,
        ]);
    }

    private function waiver(Booking $booking, User $signer): void
    {
        Waiver::create([
            'booking_id' => $booking->id,
            'type' => WaiverType::Liability,
            'signed_by' => $signer->name,
            'signature_data' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            'pdf_path' => 'waivers/demo-'.$booking->id.'.pdf',
            'signed_at' => now()->subDays(2),
            'retention_until' => now()->addYears(7),
        ]);
    }

    private function review(Booking $booking, User $student, User $instructor, int $rating, string $comment, array $tags, ?string $response): void
    {
        Review::create([
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'rating' => $rating,
            'comment' => $comment,
            'feedback_tags' => $tags,
            'instructor_response' => $response,
            'edited_at' => null,
            'is_hidden' => false,
        ]);
    }
}
