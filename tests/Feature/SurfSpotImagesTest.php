<?php

namespace Tests\Feature;

use App\Enums\InstructorLevel;
use App\Models\SurfSpot;
use App\Models\User;
use Database\Seeders\SurfSpotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SurfSpotImagesTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_ACCURACIES = [
        'exact_spot',
        'nearby_coastline',
        'satellite_reference',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
    }

    public function test_all_seeded_surf_spots_return_valid_images_existing_on_disk(): void
    {
        $this->seed(SurfSpotSeeder::class);

        $spots = SurfSpot::query()->active()->ordered()->get();

        $this->assertCount(31, $spots);

        $seenUrls = [];

        foreach ($spots as $spot) {
            $payload = $spot->toMapPayload();

            $this->assertArrayHasKey('images', $payload, "{$spot->name} is missing images in toMapPayload()");
            $this->assertIsArray($payload['images']);
            $this->assertGreaterThanOrEqual(
                2,
                count($payload['images']),
                "{$spot->name} (slug: {$spot->imageSlug()}) should have at least 2 images"
            );

            foreach ($payload['images'] as $index => $image) {
                $this->assertArrayHasKey('url', $image);
                $this->assertArrayHasKey('caption', $image);
                $this->assertArrayHasKey('accuracy', $image);
                $this->assertArrayHasKey('width', $image);
                $this->assertArrayHasKey('height', $image);

                $this->assertIsString($image['url']);
                $this->assertStringStartsWith('/images/surf-spots/', $image['url']);
                $this->assertIsString($image['caption']);
                $this->assertNotSame('', trim($image['caption']));
                $this->assertContains($image['accuracy'], self::VALID_ACCURACIES);
                $this->assertIsInt($image['width']);
                $this->assertGreaterThan(0, $image['width']);
                $this->assertIsInt($image['height']);
                $this->assertGreaterThan(0, $image['height']);

                $diskPath = public_path(ltrim($image['url'], '/'));
                $this->assertFileExists(
                    $diskPath,
                    "Image #{$index} for {$spot->name} is missing on disk: {$diskPath}"
                );

                $seenUrls[$image['url']] = true;
            }
        }

        $this->assertCount(62, $seenUrls);
    }

    public function test_image_slug_normalizes_sections_apostrophes_and_unmapped_spots(): void
    {
        $cloud9Inner = new SurfSpot(['name' => 'Cloud 9 (Inner)']);
        $cloud9Outer = new SurfSpot(['name' => 'Cloud 9 (Outer)']);
        $cloud9Lower = new SurfSpot(['name' => 'Cloud 9 (inner)']);
        $stimpysStraight = new SurfSpot(['name' => "Stimpy's (Outer)"]);
        $stimpysCurly = new SurfSpot(['name' => 'Stimpy’s (Outer)']);
        $unmapped = new SurfSpot(['name' => 'Mystery Secret Reef (Outer)']);
        $empty = new SurfSpot(['name' => '']);

        $this->assertSame('cloud-9', $cloud9Inner->imageSlug());
        $this->assertSame('cloud-9', $cloud9Outer->imageSlug());
        $this->assertSame('cloud-9', $cloud9Lower->imageSlug());
        $this->assertSame($cloud9Inner->images(), $cloud9Outer->images());
        $this->assertNotEmpty($cloud9Inner->images());

        $this->assertSame('stimpys', $stimpysStraight->imageSlug());
        $this->assertSame('stimpys', $stimpysCurly->imageSlug());
        $this->assertNotEmpty($stimpysStraight->images());

        $this->assertSame('mystery-secret-reef', $unmapped->imageSlug());
        $this->assertSame([], $unmapped->images());
        $this->assertSame([], $unmapped->toMapPayload()['images']);

        $this->assertSame('', $empty->imageSlug());
        $this->assertSame([], $empty->images());
    }

    public function test_authenticated_student_receives_surf_spot_images_on_booking_create(): void
    {
        $this->seed(SurfSpotSeeder::class);

        $student = User::factory()->create(['role' => 'student']);
        $student->assignRole('student');
        $student->studentProfile()->create([
            'skill_level' => 'advanced',
            'is_first_time' => false,
            'status' => 'active',
        ]);

        $instructor = User::factory()->create(['role' => 'instructor']);
        $instructor->assignRole('instructor');
        $instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level3,
            'status' => 'active',
            'rate_per_hour' => 1000,
        ]);

        $response = $this->actingAs($student)
            ->get(route('bookings.create', $instructor->id));

        $response->assertOk()
            ->assertInertia(function (AssertableJson $page): void {
                $page->component('Booking/Create')
                    ->has('surfSpots', 31);

                $surfSpots = $page->toArray()['props']['surfSpots'];

                foreach ($surfSpots as $spot) {
                    $this->assertArrayHasKey('images', $spot);
                    $this->assertIsArray($spot['images']);
                    $this->assertNotEmpty(
                        $spot['images'],
                        "Booking/Create prop for {$spot['name']} has an empty images array"
                    );
                    $this->assertArrayHasKey('url', $spot['images'][0]);
                    $this->assertArrayHasKey('caption', $spot['images'][0]);
                    $this->assertArrayHasKey('accuracy', $spot['images'][0]);
                    $this->assertArrayHasKey('width', $spot['images'][0]);
                    $this->assertArrayHasKey('height', $spot['images'][0]);
                }
            });
    }
}
