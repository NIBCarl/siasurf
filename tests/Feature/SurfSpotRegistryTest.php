<?php

namespace Tests\Feature;

use App\Models\SurfSpot;
use App\Models\User;
use App\Services\LocationService;
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
            'name' => 'Cloud 9',
            'area' => 'General Luna',
            'difficulty' => 'advanced',
            'is_safe_zone' => false,
        ]);

        $this->assertDatabaseHas('surf_spots', [
            'name' => 'Quicksilver',
            'area' => 'General Luna',
            'is_safe_zone' => true,
        ]);

        // Breaks outside General Luna prove the area column is real LGU data.
        $this->assertDatabaseHas('surf_spots', ['name' => 'Pacifico Beach', 'area' => 'San Isidro']);
        $this->assertDatabaseHas('surf_spots', ['name' => 'Portillo', 'area' => 'Santa Monica']);
        $this->assertDatabaseHas('surf_spots', ['name' => 'Pilar', 'area' => 'Pilar']);
        $this->assertDatabaseHas('surf_spots', ['name' => 'Burgos', 'area' => 'Burgos']);
    }

    public function test_every_seeded_break_has_coordinates_inside_siargao(): void
    {
        $this->seed(SurfSpotSeeder::class);

        SurfSpot::all()->each(function (SurfSpot $spot) {
            $this->assertNotNull($spot->latitude, "{$spot->name} is missing latitude");
            $this->assertNotNull($spot->longitude, "{$spot->name} is missing longitude");

            $this->assertTrue(
                app(LocationService::class)->isWithinSiargao(
                    (float) $spot->latitude,
                    (float) $spot->longitude
                ),
                "{$spot->name} sits outside the Siargao bounding box"
            );
        });
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(SurfSpotSeeder::class);
        $first = SurfSpot::count();

        $this->seed(SurfSpotSeeder::class);

        $this->assertSame($first, SurfSpot::count());
    }

    public function test_public_map_page_lists_active_breaks(): void
    {
        $this->seed(SurfSpotSeeder::class);

        $this->get(route('surf-spots.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/SurfSpots/Index')
                ->has('spots')
                ->has('areas')
            );
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
