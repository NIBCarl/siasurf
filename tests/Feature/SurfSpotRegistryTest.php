<?php

namespace Tests\Feature;

use App\Models\SurfSpot;
use App\Models\User;
use Database\Seeders\SurfSpotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Siargao surf-spot registry: canonical seed data, the public map, and the
 * SISA correction workflow.
 */
class SurfSpotRegistryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('admin');
    }

    public function test_seeder_creates_the_canonical_siargao_breaks_with_areas(): void
    {
        $this->seed(SurfSpotSeeder::class);

        $this->assertDatabaseHas('surf_spots', [
            'name' => 'Cloud 9 (Outer)',
            'area' => 'General Luna',
            'side' => 'east',
            'section' => 'outer',
            'difficulty' => 'advanced',
            'is_safe_zone' => false,
        ]);

        $this->assertDatabaseHas('surf_spots', [
            'name' => 'Cloud 9 (Inner)',
            'area' => 'General Luna',
            'side' => 'east',
            'section' => 'inner',
            'difficulty' => 'beginner',
            'is_safe_zone' => true,
        ]);

        // Inner and outer sections are separate records because they carry
        // different difficulty ratings, even though they share a line-up.
        $this->assertDatabaseHas('surf_spots', [
            'name' => 'Jacking Horse (Inner)',
            'difficulty' => 'beginner',
            'is_safe_zone' => true,
        ]);
        $this->assertDatabaseHas('surf_spots', [
            'name' => 'Jacking Horse (Outer)',
            'difficulty' => 'intermediate',
            'is_safe_zone' => false,
        ]);

        // Breaks outside General Luna prove the area and coast columns are real.
        $this->assertDatabaseHas('surf_spots', [
            'name' => 'Pacifico (Inner)',
            'area' => 'San Isidro',
            'side' => 'north',
        ]);
        $this->assertDatabaseHas('surf_spots', [
            'name' => 'Burgos (Outer)',
            'area' => 'Burgos',
            'side' => 'north',
            'difficulty' => 'intermediate',
        ]);
        $this->assertDatabaseHas('surf_spots', [
            'name' => 'Salvacion (Outer)',
            'area' => 'Pilar',
            'side' => 'north',
        ]);
        $this->assertDatabaseHas('surf_spots', [
            'name' => 'Giwan (Outer)',
            'area' => 'Dapa',
            'side' => 'south',
            'difficulty' => 'beginner',
        ]);
    }

    public function test_seeder_matches_the_source_directory_composition(): void
    {
        $this->seed(SurfSpotSeeder::class);

        // 33 bookable entries collapse to 31 breaks: Salvacion is listed once
        // as North and once as East, and Burgos (Outer) is listed once as
        // Intermediate and once as Expert.
        $this->assertSame(31, SurfSpot::count());

        $this->assertSame(14, SurfSpot::where('difficulty', 'beginner')->count());
        $this->assertSame(14, SurfSpot::where('difficulty', 'intermediate')->count());
        $this->assertSame(3, SurfSpot::where('difficulty', 'advanced')->count());

        $this->assertSame(5, SurfSpot::where('side', 'north')->count());
        $this->assertSame(24, SurfSpot::where('side', 'east')->count());
        $this->assertSame(2, SurfSpot::where('side', 'south')->count());
        $this->assertSame(0, SurfSpot::where('side', 'west')->count());

        $this->assertSame(1, SurfSpot::where('name', 'Salvacion (Outer)')->count());
        $this->assertSame(1, SurfSpot::where('name', 'Burgos (Outer)')->count());
    }

    public function test_every_seeded_break_carries_its_zone_and_plus_code(): void
    {
        $this->seed(SurfSpotSeeder::class);

        SurfSpot::all()->each(function (SurfSpot $spot) {
            $this->assertContains($spot->side, ['north', 'east', 'south'], "{$spot->name} has no coast side");
            $this->assertContains($spot->section, ['inner', 'outer'], "{$spot->name} has no break zone");
            $this->assertNotEmpty($spot->barangay, "{$spot->name} is missing its barangay");
            $this->assertNotEmpty($spot->plus_code, "{$spot->name} is missing its plus code");
            $this->assertNotEmpty($spot->best_season, "{$spot->name} is missing its best season");
            $this->assertNotEmpty($spot->access, "{$spot->name} is missing its access notes");
        });
    }

    public function test_only_beginner_breaks_are_marked_as_safe_zones(): void
    {
        $this->seed(SurfSpotSeeder::class);

        SurfSpot::where('difficulty', 'beginner')->each(function (SurfSpot $spot) {
            $this->assertTrue($spot->is_safe_zone, "{$spot->name} should be a beginner safe zone");
        });

        SurfSpot::where('difficulty', '!=', 'beginner')->each(function (SurfSpot $spot) {
            $this->assertFalse($spot->is_safe_zone, "{$spot->name} must not be a beginner safe zone");
        });
    }

    public function test_every_seeded_break_has_coordinates_inside_siargao(): void
    {
        $this->seed(SurfSpotSeeder::class);

        SurfSpot::all()->each(function (SurfSpot $spot) {
            $this->assertNotNull($spot->latitude, "{$spot->name} is missing latitude");
            $this->assertNotNull($spot->longitude, "{$spot->name} is missing longitude");

            // Siargao Island bounding box. Static spot coordinates only —
            // SiaSurf does not track people or live sessions.
            $inSiargao = (float) $spot->latitude >= 9.60
                && (float) $spot->latitude <= 10.10
                && (float) $spot->longitude >= 125.95
                && (float) $spot->longitude <= 126.30;

            $this->assertTrue($inSiargao, "{$spot->name} sits outside the Siargao bounding box");
        });
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(SurfSpotSeeder::class);
        $first = SurfSpot::count();

        $this->seed(SurfSpotSeeder::class);

        $this->assertSame($first, SurfSpot::count());
    }

    public function test_salvacion_matches_the_google_maps_place_pin(): void
    {
        $this->seed(SurfSpotSeeder::class);

        // https://www.google.com/maps/place/Salvacion+Surf+Spot/…!3d9.8553786!4d126.1127224
        $spot = SurfSpot::where('name', 'Salvacion (Outer)')->firstOrFail();

        $this->assertEqualsWithDelta(9.8553786, (float) $spot->latitude, 0.0000001);
        $this->assertEqualsWithDelta(126.1127224, (float) $spot->longitude, 0.0000001);
        $this->assertSame('V447+53, Pilar', $spot->plus_code);
    }

    public function test_public_map_page_lists_active_breaks(): void
    {
        $this->seed(SurfSpotSeeder::class);

        $this->get(route('surf-spots.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/SurfSpots/Index')
                ->has('spots', 31)
                ->has('areas')
                ->has('sides')
                ->has('sections')
            );
    }

    public function test_public_json_feed_filters_by_coast_and_zone(): void
    {
        $this->seed(SurfSpotSeeder::class);

        $this->getJson(route('api.surf-spots', ['side' => 'north']))
            ->assertOk()
            ->assertJsonCount(5, 'spots')
            ->assertJsonPath('sides.north', 'North');

        $this->getJson(route('api.surf-spots', ['side' => 'north', 'section' => 'inner']))
            ->assertOk()
            ->assertJsonCount(2, 'spots');

        // The source publishes no West breaks: an empty list, not an error.
        $this->getJson(route('api.surf-spots', ['side' => 'west']))
            ->assertOk()
            ->assertJsonCount(0, 'spots');
    }

    public function test_admin_can_save_coast_classification(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.surf-spots.store'), [
                'name' => 'Test Reef',
                'area' => 'Pilar',
                'barangay' => 'Salvacion',
                'side' => 'north',
                'section' => 'outer',
                'difficulty' => 'intermediate',
                'best_tide' => 'Mid to high',
                'best_season' => 'January to March',
                'access' => 'Boat only',
                'plus_code' => 'V447+53, Pilar',
                'latitude' => 9.8554,
                'longitude' => 126.1127,
                'is_active' => true,
                'is_safe_zone' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('surf_spots', [
            'name' => 'Test Reef',
            'area' => 'Pilar',
            'side' => 'north',
            'section' => 'outer',
            'access' => 'Boat only',
        ]);
    }

    public function test_admin_cannot_save_an_unknown_coast_side(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.surf-spots.store'), [
                'name' => 'Bad Side Break',
                'area' => 'General Luna',
                'side' => 'middle',
                'difficulty' => 'beginner',
                'latitude' => 9.79,
                'longitude' => 126.15,
            ])
            ->assertSessionHasErrors(['side']);
    }

    public function test_admin_can_add_a_surf_spot(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.surf-spots.store'), [
                'name' => 'Test Break',
                'area' => 'Dapa',
                'difficulty' => 'intermediate',
                'latitude' => 9.7600,
                'longitude' => 126.0500,
                'is_active' => true,
                'is_safe_zone' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('surf_spots', [
            'name' => 'Test Break',
            'area' => 'Dapa',
        ]);
    }

    public function test_a_beginner_break_is_automatically_flagged_as_a_safe_zone(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.surf-spots.store'), [
                'name' => 'Auto Safe Break',
                'area' => 'General Luna',
                'difficulty' => 'beginner',
                'latitude' => 9.7900,
                'longitude' => 126.1500,
                'is_active' => true,
                'is_safe_zone' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('surf_spots', [
            'name' => 'Auto Safe Break',
            'is_safe_zone' => true,
        ]);
    }

    public function test_coordinates_outside_siargao_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.surf-spots.store'), [
                'name' => 'Manila Break',
                'area' => 'Manila',
                'difficulty' => 'beginner',
                'latitude' => 14.5995,
                'longitude' => 120.9842,
            ])
            ->assertSessionHasErrors(['latitude', 'longitude']);
    }

    public function test_non_admin_cannot_manage_surf_spots(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');

        $this->actingAs($student)
            ->post(route('admin.surf-spots.store'), [
                'name' => 'Sneaky Break',
                'area' => 'General Luna',
                'difficulty' => 'beginner',
                'latitude' => 9.79,
                'longitude' => 126.15,
            ])
            ->assertForbidden();
    }
}
