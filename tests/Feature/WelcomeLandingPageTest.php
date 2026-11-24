<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\CertificateStatus;
use App\Enums\InstructorLevel;
use App\Enums\InstructorStatus;
use App\Enums\SkillLevel;
use App\Events\LandingDataUpdated;
use App\Models\Booking;
use App\Models\Certificate;
use App\Models\InstructorProfile;
use App\Models\Review;
use App\Models\SurfSpot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WelcomeLandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
    }

    public function test_empty_database_renders_static_fallback_cards_and_stats(): void
    {
        $this->get(route('welcome'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('stats.total_spots', 31)
                ->where('stats.beginner_safe_spots', 14)
                ->where('stats.verified_instructors', 0)
                ->where('stats.base_rate', 600)
                ->has('featuredSpots', 3)
                ->where('featuredSpots.0.name', 'Cloud 9')
                ->where('featuredSpots.1.name', 'Quicksilver')
                ->where('featuredSpots.2.name', 'Jacking Horse')
                ->has('featuredInstructors', 2)
                ->where('featuredInstructors.0.name', "Junrey 'Bogs' Capistrano")
                ->where('featuredInstructors.1.name', "Ana 'Maui' San Jose")
                ->has('recentReviews', 3)
                ->where('reviewStats.total_reviews', 0)
            );
    }

    public function test_populated_database_renders_live_eligible_spots_instructors_and_visible_reviews(): void
    {
        $spotAdv = SurfSpot::factory()->advanced()->create([
            'name' => 'Cloud 9 (Outer)',
            'area' => 'General Luna',
            'is_active' => true,
            'is_safe_zone' => false,
        ]);
        $spotInt = SurfSpot::factory()->intermediate()->create([
            'name' => 'Quick Silver (Outer)',
            'area' => 'General Luna',
            'is_active' => true,
            'is_safe_zone' => false,
        ]);
        $spotBeg = SurfSpot::factory()->beginner()->create([
            'name' => 'Jacking Horse (Inner)',
            'area' => 'General Luna',
            'is_active' => true,
            'is_safe_zone' => true,
        ]);
        SurfSpot::factory()->beginner()->inactive()->create([
            'name' => 'Inactive Secret Break',
            'is_safe_zone' => true,
        ]);

        // Eligible active instructors
        $verifiedOne = $this->createDiscoverableInstructor('Kuya Marco', InstructorLevel::Level3, 750);
        $verifiedTwo = $this->createDiscoverableInstructor('Ate Carla', InstructorLevel::Level2, 850);

        // Ineligible instructors that MUST NOT surface
        $pending = User::factory()->instructor()->create(['name' => 'Pending Coach']);
        $pending->instructorProfile()->create([
            'level' => InstructorLevel::Level3,
            'status' => InstructorStatus::PendingVerification->value,
            'rate_per_hour' => 500,
        ]);
        Certificate::factory()->verified()->create(['user_id' => $pending->id]);

        $suspended = User::factory()->instructor()->create(['name' => 'Suspended Coach']);
        $suspended->instructorProfile()->create([
            'level' => InstructorLevel::Level3,
            'status' => InstructorStatus::Suspended->value,
            'suspended_until' => now()->addWeek(),
            'rate_per_hour' => 500,
        ]);
        Certificate::factory()->verified()->create(['user_id' => $suspended->id]);

        $inactive = User::factory()->instructor()->create(['name' => 'Inactive Coach']);
        $inactive->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => InstructorStatus::Inactive->value,
            'rate_per_hour' => 500,
        ]);
        Certificate::factory()->verified()->create(['user_id' => $inactive->id]);

        $rejected = User::factory()->instructor()->create(['name' => 'Rejected Coach']);
        $rejected->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => InstructorStatus::Rejected->value,
            'rate_per_hour' => 500,
        ]);
        Certificate::factory()->verified()->create(['user_id' => $rejected->id]);

        $expiredCert = User::factory()->instructor()->create(['name' => 'Expired Cert Coach']);
        $expiredCert->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => 500,
        ]);
        Certificate::factory()->expired()->create(['user_id' => $expiredCert->id]);

        $student = User::factory()->student()->create(['name' => 'Elena Surfer']);
        $student->assignRole('student');

        $booking = Booking::factory()->completed()->create([
            'student_id' => $student->id,
            'instructor_id' => $verifiedOne->id,
            'surf_spot_id' => $spotBeg->id,
            'status' => BookingStatus::Completed->value,
        ]);

        Review::factory()->create([
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'instructor_id' => $verifiedOne->id,
            'rating' => 5,
            'comment' => 'Amazing safety briefing and clear instruction at Jacking Horse.',
            'is_hidden' => false,
        ]);

        $hiddenBooking = Booking::factory()->completed()->create([
            'student_id' => $student->id,
            'instructor_id' => $verifiedTwo->id,
            'surf_spot_id' => $spotInt->id,
            'status' => BookingStatus::Completed->value,
        ]);

        Review::factory()->hidden()->create([
            'booking_id' => $hiddenBooking->id,
            'student_id' => $student->id,
            'instructor_id' => $verifiedTwo->id,
            'rating' => 1,
            'comment' => 'This hidden review must not appear on the landing page.',
        ]);

        $this->get(route('welcome'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('stats.total_spots', 3)
                ->where('stats.beginner_safe_spots', 1)
                ->where('stats.verified_instructors', 2)
                ->where('stats.base_rate', 750)
                ->where('stats.completed_sessions', 2)
                ->has('featuredSpots', 3)
                ->where('featuredSpots.0.id', $spotAdv->id)
                ->where('featuredSpots.1.id', $spotInt->id)
                ->where('featuredSpots.2.id', $spotBeg->id)
                ->has('featuredInstructors', 2)
                ->where('featuredInstructors.0.id', $verifiedOne->id)
                ->where('featuredInstructors.0.name', 'Kuya Marco')
                ->where('featuredInstructors.0.total_reviews', 1)
                ->missing('featuredInstructors.0.email')
                ->missing('featuredInstructors.0.phone')
                ->where('featuredInstructors.1.id', $verifiedTwo->id)
                ->where('featuredInstructors.1.name', 'Ate Carla')
                ->missing('featuredInstructors.1.email')
                ->missing('featuredInstructors.1.phone')
                ->has('recentReviews', 1)
                ->where('recentReviews.0.student_name', 'Elena Surfer')
                ->where('recentReviews.0.instructor_name', 'Kuya Marco')
                ->where('recentReviews.0.surf_spot', 'Jacking Horse (Inner)')
                ->where('reviewStats.total_reviews', 1)
                ->where('reviewStats.average_rating', 5)
            );
    }

    public function test_authenticated_advanced_student_only_gets_compatible_level_3_instructor_profile_url(): void
    {
        $level1 = $this->createDiscoverableInstructor('Beginner Only Coach', InstructorLevel::Level1, 600);
        $level3 = $this->createDiscoverableInstructor('Expert Coach', InstructorLevel::Level3, 1500);

        $student = User::factory()->student()->create();
        $student->assignRole('student');
        $student->studentProfile()->create([
            'skill_level' => SkillLevel::Advanced->value,
        ]);

        $this->actingAs($student)
            ->get(route('welcome'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->has('featuredInstructors', 1)
                ->where('featuredInstructors.0.id', $level3->id)
                ->where('featuredInstructors.0.profile_url', route('instructors.profile', $level3->id))
            );

        $this->assertNotEquals($level1->id, $level3->id);
    }

    public function test_landing_data_updated_event_dispatches_on_spot_instructor_and_review_saves(): void
    {
        Event::fake([LandingDataUpdated::class]);

        SurfSpot::factory()->beginner()->create([
            'name' => 'Salvacion (Inner)',
            'is_safe_zone' => true,
        ]);

        Event::assertDispatched(
            LandingDataUpdated::class,
            fn (LandingDataUpdated $event): bool => $event->section === 'spots'
        );

        $instructor = User::factory()->instructor()->create();
        $instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => 800,
        ]);

        Event::assertDispatched(
            LandingDataUpdated::class,
            fn (LandingDataUpdated $event): bool => $event->section === 'instructors'
        );

        $student = User::factory()->student()->create();
        $booking = Booking::factory()->completed()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
        ]);

        Review::factory()->create([
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'comment' => 'Great lesson and very clear instructions throughout.',
        ]);

        Event::assertDispatched(
            LandingDataUpdated::class,
            fn (LandingDataUpdated $event): bool => $event->section === 'reviews'
        );
    }

    private function createDiscoverableInstructor(string $name, InstructorLevel $level, float $rate): User
    {
        $user = User::factory()->instructor()->create([
            'name' => $name,
            'email' => str($name)->slug()->append('@example.com')->toString(),
            'phone' => '+639171234567',
        ]);
        $user->assignRole('instructor');

        $user->instructorProfile()->create([
            'level' => $level,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => $rate,
            'area' => 'General Luna',
            'bio' => 'Certified SISA instructor at General Luna.',
        ]);

        Certificate::factory()->verified()->create([
            'user_id' => $user->id,
            'expires_at' => now()->addYear(),
        ]);

        return $user;
    }
}
