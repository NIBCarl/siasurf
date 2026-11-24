<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\InstructorLevel;
use App\Enums\SessionType;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Certificate;
use App\Models\SurfSpot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorSurfZonesAndOfferingsTest extends TestCase
{
    use RefreshDatabase;

    private SurfSpot $beginnerSpotA;

    private SurfSpot $beginnerSpotB;

    private SurfSpot $beginnerSpotC;

    private SurfSpot $intermediateSpot;

    private SurfSpot $advancedSpot;

    private SurfSpot $inactiveBeginnerSpot;

    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');

        $this->beginnerSpotA = SurfSpot::factory()->create([
            'name' => 'Jacking Horse (Inner)',
            'area' => 'General Luna',
            'difficulty' => 'beginner',
            'is_active' => true,
            'is_safe_zone' => true,
            'latitude' => 9.8145653,
            'longitude' => 126.1626577,
        ]);

        $this->beginnerSpotB = SurfSpot::factory()->create([
            'name' => 'Little Tahiti (Inner)',
            'area' => 'General Luna',
            'difficulty' => 'beginner',
            'is_active' => true,
            'is_safe_zone' => true,
            'latitude' => 9.8147000,
            'longitude' => 126.1654000,
        ]);

        $this->beginnerSpotC = SurfSpot::factory()->create([
            'name' => 'Burgos (Inner)',
            'area' => 'Burgos',
            'difficulty' => 'beginner',
            'is_active' => true,
            'is_safe_zone' => true,
            'latitude' => 10.0192000,
            'longitude' => 126.0731000,
        ]);

        $this->intermediateSpot = SurfSpot::factory()->create([
            'name' => 'Tuason Point (Outer)',
            'area' => 'General Luna',
            'difficulty' => 'intermediate',
            'is_active' => true,
            'is_safe_zone' => false,
            'latitude' => 9.8090802,
            'longitude' => 126.1696516,
        ]);

        $this->advancedSpot = SurfSpot::factory()->create([
            'name' => 'Cloud 9 (Outer)',
            'area' => 'General Luna',
            'difficulty' => 'advanced',
            'is_active' => true,
            'is_safe_zone' => false,
            'latitude' => 9.8140113,
            'longitude' => 126.1668101,
        ]);

        $this->inactiveBeginnerSpot = SurfSpot::factory()->inactive()->create([
            'name' => 'Closed Reef',
            'area' => 'General Luna',
            'difficulty' => 'beginner',
        ]);

        $this->day = now()->addDays(4)->startOfDay();
    }

    private function createStudent(string $skill = 'beginner'): User
    {
        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');
        $student->studentProfile()->create([
            'skill_level' => $skill,
            'is_first_time' => $skill === 'beginner',
            'status' => 'active',
        ]);

        return $student;
    }

    private function createVerifiedInstructor(InstructorLevel $level, array $profileOverrides = []): User
    {
        $instructor = User::factory()->create(['role' => 'instructor']);
        $instructor->assignRole('instructor');

        $instructor->instructorProfile()->create(array_merge([
            'level' => $level,
            'status' => 'active',
            'rate_per_hour' => 700,
            'guiding_rate' => $level->value >= 2 ? 1100 : null,
            'coaching_rate' => $level->value >= 3 ? 1700 : null,
            'auto_accept_bookings' => false,
        ], $profileOverrides));

        Certificate::factory()->create([
            'user_id' => $instructor->id,
            'type' => CertificateType::SISA->value,
            'status' => CertificateStatus::Verified->value,
            'verified_at' => now()->subMonth(),
            'expires_at' => now()->addYear(),
        ]);

        Availability::create([
            'user_id' => $instructor->id,
            'day_of_week' => (int) $this->day->format('w'),
            'time_period' => 'morning',
            'is_available' => true,
            'start_time' => '06:00',
            'end_time' => '12:00',
            'slot_duration_minutes' => 60,
            'specific_date' => null,
        ]);

        return $instructor;
    }

    private function bookingPayload(SurfSpot $spot, array $overrides = []): array
    {
        return array_merge([
            'date' => $this->day->format('Y-m-d'),
            'start_time' => '07:00',
            'end_time' => '09:00',
            'student_age' => 24,
            'height' => 170,
            'weight' => 65,
            'student_count' => 1,
            'has_board' => false,
            'surf_spot_id' => $spot->id,
        ], $overrides);
    }

    // ---------------------------------------------------------------------
    // Requirement 1: Surf Zones by Approved Level
    // ---------------------------------------------------------------------

    public function test_level_1_instructor_can_only_select_active_beginner_surf_zones(): void
    {
        $instructor = $this->createVerifiedInstructor(InstructorLevel::Level1);

        // Edit page only exposes active beginner spots for Level 1.
        $this->actingAs($instructor)
            ->get(route('instructor.profile.edit'))
            ->assertOk()
            ->assertInertia(function (AssertableJson $page) {
                $props = $page->toArray()['props'];
                $spotIds = collect($props['availableSurfSpots'])->pluck('id')->all();

                $this->assertEqualsCanonicalizing(
                    [$this->beginnerSpotA->id, $this->beginnerSpotB->id, $this->beginnerSpotC->id],
                    $spotIds
                );
                $this->assertSame(['teaching'], $props['allowedServices']);
            });

        // Intermediate spot rejected.
        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'offered_services' => ['teaching'],
                'rate_per_hour' => 650,
                'surf_spot_ids' => [$this->intermediateSpot->id],
            ])
            ->assertSessionHasErrors('surf_spot_ids');

        // Advanced spot rejected.
        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'offered_services' => ['teaching'],
                'rate_per_hour' => 650,
                'surf_spot_ids' => [$this->advancedSpot->id],
            ])
            ->assertSessionHasErrors('surf_spot_ids');

        // Inactive beginner spot rejected.
        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'offered_services' => ['teaching'],
                'rate_per_hour' => 650,
                'surf_spot_ids' => [$this->inactiveBeginnerSpot->id],
            ])
            ->assertSessionHasErrors('surf_spot_ids');

        // Valid beginner spots succeed and sync directory coordinates.
        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'offered_services' => ['teaching'],
                'rate_per_hour' => 650,
                'surf_spot_ids' => [$this->beginnerSpotA->id, $this->beginnerSpotB->id],
            ])
            ->assertSessionHasNoErrors();

        $profile = $instructor->instructorProfile->fresh('surfSpots');
        $this->assertEqualsCanonicalizing(
            [$this->beginnerSpotA->id, $this->beginnerSpotB->id],
            $profile->surfSpots->pluck('id')->all()
        );
        $this->assertEqualsWithDelta((float) $this->beginnerSpotA->latitude, (float) $profile->latitude, 0.00001);
        $this->assertEqualsWithDelta((float) $this->beginnerSpotA->longitude, (float) $profile->longitude, 0.00001);
    }

    public function test_level_2_instructor_can_select_beginner_and_intermediate_zones_but_not_advanced(): void
    {
        $instructor = $this->createVerifiedInstructor(InstructorLevel::Level2);

        $this->actingAs($instructor)
            ->get(route('instructor.profile.edit'))
            ->assertOk()
            ->assertInertia(function (AssertableJson $page) {
                $spotIds = collect($page->toArray()['props']['availableSurfSpots'])->pluck('id')->all();

                $this->assertEqualsCanonicalizing(
                    [
                        $this->beginnerSpotA->id,
                        $this->beginnerSpotB->id,
                        $this->beginnerSpotC->id,
                        $this->intermediateSpot->id,
                    ],
                    $spotIds
                );
            });

        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'offered_services' => ['teaching', 'guiding'],
                'rate_per_hour' => 700,
                'guiding_rate' => 1100,
                'surf_spot_ids' => [$this->beginnerSpotA->id, $this->advancedSpot->id],
            ])
            ->assertSessionHasErrors('surf_spot_ids');

        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'offered_services' => ['teaching', 'guiding'],
                'rate_per_hour' => 700,
                'guiding_rate' => 1100,
                'surf_spot_ids' => [$this->beginnerSpotA->id, $this->intermediateSpot->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            [$this->beginnerSpotA->id, $this->intermediateSpot->id],
            $instructor->instructorProfile->fresh('surfSpots')->surfSpots->pluck('id')->all()
        );
    }

    public function test_level_3_instructor_can_select_all_surf_zones_and_onboarding_validates_spots_by_level(): void
    {
        Storage::fake('local');

        $level3 = $this->createVerifiedInstructor(InstructorLevel::Level3);

        $this->actingAs($level3)
            ->patch(route('instructor.profile.update'), [
                'name' => $level3->name,
                'offered_services' => ['teaching', 'guiding', 'coaching'],
                'rate_per_hour' => 700,
                'guiding_rate' => 1100,
                'coaching_rate' => 1800,
                'surf_spot_ids' => [
                    $this->beginnerSpotA->id,
                    $this->intermediateSpot->id,
                    $this->advancedSpot->id,
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            [$this->beginnerSpotA->id, $this->intermediateSpot->id, $this->advancedSpot->id],
            $level3->instructorProfile->fresh('surfSpots')->surfSpots->pluck('id')->all()
        );

        // Onboarding validates surf_spot_ids against the submitted level.
        $applicant = User::factory()->instructor()->create();
        $applicant->assignRole('instructor');

        $this->actingAs($applicant)
            ->post(route('instructor.onboarding.store'), [
                'bio' => 'New instructor applicant.',
                'level' => 1,
                'offered_services' => ['teaching'],
                'rate_per_hour' => 650,
                'surf_spot_ids' => [$this->intermediateSpot->id],
                'certificates' => [UploadedFile::fake()->create('sisa_cert.pdf', 120, 'application/pdf')],
            ])
            ->assertSessionHasErrors('surf_spot_ids');

        $this->actingAs($applicant)
            ->post(route('instructor.onboarding.store'), [
                'bio' => 'New Level 2 instructor applicant.',
                'level' => 2,
                'offered_services' => ['guiding'],
                'guiding_rate' => 1000,
                'surf_spot_ids' => [$this->beginnerSpotA->id, $this->intermediateSpot->id],
                'certificates' => [UploadedFile::fake()->create('sisa_cert.pdf', 120, 'application/pdf')],
            ])
            ->assertRedirect(route('instructor.pending'));

        $applicantProfile = $applicant->instructorProfile()->with('surfSpots')->firstOrFail();
        $this->assertSame(['guiding'], $applicantProfile->offered_services);
        $this->assertNull($applicantProfile->rate_per_hour);
        $this->assertSame('1000.00', $applicantProfile->guiding_rate);
        $this->assertEqualsCanonicalizing(
            [$this->beginnerSpotA->id, $this->intermediateSpot->id],
            $applicantProfile->surfSpots->pluck('id')->all()
        );
    }

    public function test_admin_downgrading_instructor_level_prunes_disallowed_spots_and_services(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole('admin');

        $instructor = $this->createVerifiedInstructor(InstructorLevel::Level3, [
            'offered_services' => ['coaching'],
            'rate_per_hour' => null,
            'guiding_rate' => null,
            'coaching_rate' => 1800,
        ]);
        $instructor->instructorProfile->surfSpots()->sync([
            $this->beginnerSpotA->id,
            $this->intermediateSpot->id,
            $this->advancedSpot->id,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.instructors.update', $instructor->id), [
                'name' => $instructor->name,
                'email' => $instructor->email,
                'level' => 1,
                'status' => 'active',
                'rate_per_hour' => 700,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.instructors.show', $instructor->id));

        $profile = $instructor->instructorProfile->fresh('surfSpots');

        $this->assertSame([$this->beginnerSpotA->id], $profile->surfSpots->pluck('id')->all());
        $this->assertSame(['teaching'], $profile->offered_services);
        $this->assertNull($profile->guiding_rate);
        $this->assertNull($profile->coaching_rate);
    }

    // ---------------------------------------------------------------------
    // Requirement 1b: Basis of What Student Sees & Can Book
    // ---------------------------------------------------------------------

    public function test_instructor_selected_spots_govern_student_booking_options_and_server_validation(): void
    {
        $instructor = $this->createVerifiedInstructor(InstructorLevel::Level1);
        $instructor->instructorProfile->surfSpots()->sync([
            $this->beginnerSpotA->id,
            $this->beginnerSpotB->id,
        ]);

        $beginner = $this->createStudent('beginner');

        // Booking page only lists the 2 selected beginner spots (excludes beginnerSpotC).
        $this->actingAs($beginner)
            ->get(route('bookings.create', $instructor->id))
            ->assertOk()
            ->assertInertia(function (AssertableJson $page) {
                $props = $page->toArray()['props'];
                $spotIds = collect($props['surfSpots'])->pluck('id')->all();

                $this->assertTrue($props['instructorHasCustomSpots']);
                $this->assertEqualsCanonicalizing(
                    [$this->beginnerSpotA->id, $this->beginnerSpotB->id],
                    $spotIds
                );
                $this->assertNotContains($this->beginnerSpotC->id, $spotIds);
            });

        // Direct POST for unselected beginnerSpotC must be rejected.
        $this->actingAs($beginner)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->beginnerSpotC))
            ->assertSessionHas('error');

        $this->assertSame(0, Booking::count());

        // Booking at selected beginnerSpotA succeeds.
        $this->actingAs($beginner)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->beginnerSpotA))
            ->assertRedirect();

        $this->assertSame(1, Booking::count());
    }

    public function test_level_3_instructor_spots_are_further_filtered_by_student_skill_level(): void
    {
        $instructor = $this->createVerifiedInstructor(InstructorLevel::Level3);
        $instructor->instructorProfile->surfSpots()->sync([
            $this->beginnerSpotA->id,
            $this->intermediateSpot->id,
            $this->advancedSpot->id,
        ]);

        $beginner = $this->createStudent('beginner');
        $intermediate = $this->createStudent('intermediate');
        $advanced = $this->createStudent('advanced');

        // Beginner sees only the 1 beginner spot.
        $this->actingAs($beginner)
            ->get(route('bookings.create', $instructor->id))
            ->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $this->assertSame(
                [$this->beginnerSpotA->id],
                collect($page->toArray()['props']['surfSpots'])->pluck('id')->all()
            ));

        // Intermediate sees beginner + intermediate (2).
        $this->actingAs($intermediate)
            ->get(route('bookings.create', $instructor->id))
            ->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $this->assertEqualsCanonicalizing(
                [$this->beginnerSpotA->id, $this->intermediateSpot->id],
                collect($page->toArray()['props']['surfSpots'])->pluck('id')->all()
            ));

        // Advanced sees all 3 selected spots.
        $this->actingAs($advanced)
            ->get(route('bookings.create', $instructor->id))
            ->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $this->assertEqualsCanonicalizing(
                [$this->beginnerSpotA->id, $this->intermediateSpot->id, $this->advancedSpot->id],
                collect($page->toArray()['props']['surfSpots'])->pluck('id')->all()
            ));

        // Public profile renders the instructor's selected spots and offered services.
        $this->get(route('instructors.profile', $instructor->id))
            ->assertOk()
            ->assertInertia(function (AssertableJson $page) {
                $props = $page->toArray()['props'];
                $this->assertEqualsCanonicalizing(
                    [$this->beginnerSpotA->id, $this->intermediateSpot->id, $this->advancedSpot->id],
                    collect($props['surfSpots'])->pluck('id')->all()
                );
                $this->assertCount(3, $props['serviceRates']);
            });
    }

    public function test_directory_search_hides_instructor_when_their_spots_or_services_exclude_the_student(): void
    {
        // Level 2 instructor who ONLY offers Guiding and ONLY teaches at an intermediate spot.
        $guidingOnlyInstructor = $this->createVerifiedInstructor(InstructorLevel::Level2, [
            'offered_services' => ['guiding'],
            'rate_per_hour' => null,
            'guiding_rate' => 1150,
            'area' => null,
        ]);
        $guidingOnlyInstructor->instructorProfile->surfSpots()->sync([$this->intermediateSpot->id]);

        // Level 1 instructor teaching at a beginner spot.
        $beginnerInstructor = $this->createVerifiedInstructor(InstructorLevel::Level1, [
            'offered_services' => ['teaching'],
            'rate_per_hour' => 650,
        ]);
        $beginnerInstructor->instructorProfile->surfSpots()->sync([$this->beginnerSpotA->id]);

        $beginner = $this->createStudent('beginner');
        $intermediate = $this->createStudent('intermediate');

        // Beginner sees beginnerInstructor, NOT guidingOnlyInstructor.
        $this->actingAs($beginner)
            ->get(route('instructors.search'))
            ->assertOk()
            ->assertInertia(function (AssertableJson $page) use ($beginnerInstructor, $guidingOnlyInstructor) {
                $ids = collect($page->toArray()['props']['instructors']['data'])->pluck('id')->all();
                $this->assertContains($beginnerInstructor->id, $ids);
                $this->assertNotContains($guidingOnlyInstructor->id, $ids);
            });

        // Intermediate sees guidingOnlyInstructor, NOT beginnerInstructor.
        $this->actingAs($intermediate)
            ->get(route('instructors.search'))
            ->assertOk()
            ->assertInertia(function (AssertableJson $page) use ($beginnerInstructor, $guidingOnlyInstructor) {
                $ids = collect($page->toArray()['props']['instructors']['data'])->pluck('id')->all();
                $this->assertContains($guidingOnlyInstructor->id, $ids);
                $this->assertNotContains($beginnerInstructor->id, $ids);
            });

        // Public profile for guidingOnlyInstructor shows a clear block reason to a beginner student.
        $this->actingAs($beginner)
            ->get(route('instructors.profile', $guidingOnlyInstructor->id))
            ->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $page
                ->where('startingRate', 1150)
                ->whereType('studentBookingBlockReason', 'string')
            );
    }

    // ---------------------------------------------------------------------
    // Requirement 2: Lesson Types by Approved Level
    // ---------------------------------------------------------------------

    public function test_lesson_types_are_restricted_to_instructor_approved_level(): void
    {
        $level1 = $this->createVerifiedInstructor(InstructorLevel::Level1);
        $level2 = $this->createVerifiedInstructor(InstructorLevel::Level2);
        $level3 = $this->createVerifiedInstructor(InstructorLevel::Level3);

        // Level 1 cannot offer guiding or coaching.
        $this->actingAs($level1)
            ->patch(route('instructor.profile.update'), [
                'name' => $level1->name,
                'offered_services' => ['teaching', 'guiding'],
                'rate_per_hour' => 650,
            ])
            ->assertSessionHasErrors('offered_services.1');

        // Level 2 cannot offer coaching or empty selection.
        $this->actingAs($level2)
            ->patch(route('instructor.profile.update'), [
                'name' => $level2->name,
                'offered_services' => ['teaching', 'coaching'],
                'rate_per_hour' => 700,
                'coaching_rate' => 1600,
            ])
            ->assertSessionHasErrors('offered_services.1');

        $this->actingAs($level2)
            ->patch(route('instructor.profile.update'), [
                'name' => $level2->name,
                'offered_services' => [],
            ])
            ->assertSessionHasErrors('offered_services');

        // Level 3 can offer any non-empty subset, e.g., only coaching, or teaching + coaching.
        $this->actingAs($level3)
            ->patch(route('instructor.profile.update'), [
                'name' => $level3->name,
                'offered_services' => ['teaching', 'coaching'],
                'rate_per_hour' => 750,
                'coaching_rate' => 1850,
            ])
            ->assertSessionHasNoErrors();

        $profile3 = $level3->instructorProfile->fresh();
        $this->assertSame(['teaching', 'coaching'], $profile3->offered_services);
        $this->assertSame('750.00', $profile3->rate_per_hour);
        $this->assertNull($profile3->guiding_rate);
        $this->assertSame('1850.00', $profile3->coaching_rate);
    }

    public function test_booking_flow_respects_instructor_offered_lesson_types_only(): void
    {
        // Level 3 offers Teaching + Coaching (opted OUT of Guiding).
        $instructor = $this->createVerifiedInstructor(InstructorLevel::Level3, [
            'offered_services' => ['teaching', 'coaching'],
            'rate_per_hour' => 750,
            'guiding_rate' => null,
            'coaching_rate' => 1850,
        ]);
        $instructor->instructorProfile->surfSpots()->sync([
            $this->beginnerSpotA->id,
            $this->intermediateSpot->id,
            $this->advancedSpot->id,
        ]);

        $intermediate = $this->createStudent('intermediate');

        // Because the instructor opted out of Guiding (strictly assigned to Intermediate),
        // an Intermediate student sees no available service options and cannot book.
        $this->actingAs($intermediate)
            ->get(route('bookings.create', $instructor->id))
            ->assertOk()
            ->assertInertia(function (AssertableJson $page) {
                $props = $page->toArray()['props'];
                $types = collect($props['serviceOptions'])->pluck('type')->all();

                $this->assertSame([], $types);
                $this->assertSame('', $props['defaultSessionType']);
            });

        // Attempting to book Guiding or substitute Coaching as Intermediate is rejected.
        $this->actingAs($intermediate)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->intermediateSpot, [
                'session_type' => 'guiding',
            ]))
            ->assertSessionHas('error');

        $this->actingAs($intermediate)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->intermediateSpot, [
                'session_type' => 'coaching',
            ]))
            ->assertSessionHas('error');

        $this->assertSame(0, Booking::count());

        // Advanced student IS assigned to Coaching and sees + books Coaching at ₱1,850/hr.
        $advanced = $this->createStudent('advanced');

        $this->actingAs($advanced)
            ->get(route('bookings.create', $instructor->id))
            ->assertOk()
            ->assertInertia(function (AssertableJson $page) {
                $props = $page->toArray()['props'];
                $types = collect($props['serviceOptions'])->pluck('type')->all();

                $this->assertSame(['coaching'], $types);
                $this->assertSame('coaching', $props['defaultSessionType']);
                $this->assertSame(120, $props['minimumDurationMinutes']);
            });

        $this->actingAs($advanced)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->advancedSpot))
            ->assertRedirect();

        $booking = Booking::firstOrFail();
        $this->assertSame(SessionType::Coaching, $booking->session_type);
        $this->assertSame('1850.00', $booking->hourly_rate);
        $this->assertSame('3700.00', $booking->lesson_price);
        $this->assertSame('259.00', $booking->service_fee_amount);
        $this->assertSame('3959.00', $booking->total_amount);
    }

    // ---------------------------------------------------------------------
    // Requirement 3: Per-Lesson-Type Hourly Rates Within SISA Range
    // ---------------------------------------------------------------------

    public function test_per_service_hourly_rate_bounds_are_enforced_when_offered_and_ignored_when_unchecked(): void
    {
        $instructor = $this->createVerifiedInstructor(InstructorLevel::Level3);

        // Teaching out of range (599 / 1001) rejected.
        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'offered_services' => ['teaching'],
                'rate_per_hour' => 599,
            ])
            ->assertSessionHasErrors('rate_per_hour');

        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'offered_services' => ['teaching'],
                'rate_per_hour' => 1001,
            ])
            ->assertSessionHasErrors('rate_per_hour');

        // Guiding out of range (799 / 1501) rejected when offered.
        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'offered_services' => ['guiding'],
                'guiding_rate' => 799,
            ])
            ->assertSessionHasErrors('guiding_rate');

        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'offered_services' => ['guiding'],
                'guiding_rate' => 1501,
            ])
            ->assertSessionHasErrors('guiding_rate');

        // Coaching out of range (1499 / 2001) rejected when offered.
        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'offered_services' => ['coaching'],
                'coaching_rate' => 1499,
            ])
            ->assertSessionHasErrors('coaching_rate');

        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'offered_services' => ['coaching'],
                'coaching_rate' => 2001,
            ])
            ->assertSessionHasErrors('coaching_rate');

        // Offering ONLY Coaching at ₱2,000/hr succeeds without rate_per_hour or guiding_rate.
        $this->actingAs($instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $instructor->name,
                'offered_services' => ['coaching'],
                'rate_per_hour' => null,
                'guiding_rate' => null,
                'coaching_rate' => 2000,
                'surf_spot_ids' => [$this->advancedSpot->id],
            ])
            ->assertSessionHasNoErrors();

        $profile = $instructor->instructorProfile->fresh();
        $this->assertSame(['coaching'], $profile->offered_services);
        $this->assertNull($profile->rate_per_hour);
        $this->assertNull($profile->guiding_rate);
        $this->assertSame('2000.00', $profile->coaching_rate);
        $this->assertSame(2000.0, $profile->startingRate());

        // Advanced student booking pays ₱2,000/hr × 2 hrs = ₱4,000.
        $advanced = $this->createStudent('advanced');
        $this->actingAs($advanced)
            ->post(route('bookings.store-details', $instructor->id), $this->bookingPayload($this->advancedSpot, [
                'session_type' => 'coaching',
            ]))
            ->assertRedirect();

        $booking = Booking::firstOrFail();
        $this->assertSame('2000.00', $booking->hourly_rate);
        $this->assertSame('4000.00', $booking->lesson_price);
        $this->assertSame('280.00', $booking->service_fee_amount);
        $this->assertSame('4280.00', $booking->total_amount);
    }
}
