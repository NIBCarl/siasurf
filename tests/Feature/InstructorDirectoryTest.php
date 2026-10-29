<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\InstructorLevel;
use App\Models\Certificate;
use App\Models\SurfSpot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 1000,
            'area' => 'General Luna',
            'meeting_point' => 'Cloud 9 tower',
            'latitude' => 9.8119,
            'longitude' => 126.1672,
        ]);

        Certificate::factory()->create([
            'user_id' => $this->instructor->id,
            'type' => CertificateType::BLS->value,
            'status' => CertificateStatus::Verified->value,
            'verified_at' => now()->subMonth(),
            'expires_at' => now()->addYear(),
        ]);

        SurfSpot::factory()->create(['difficulty' => 'beginner']);
    }

    public function test_guests_can_browse_the_directory_and_map_data(): void
    {
        $this->get(route('instructors.search'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Instructors/Search')
                ->has('areas', 1)
                ->where('areas.0', 'General Luna')
                ->where('instructors.data.0.instructor_profile.area', 'General Luna')
                ->where('instructors.data.0.map.latitude', 9.8119)
                ->where('instructors.data.0.map.longitude', 126.1672)
                ->where('mapCenter.zoom', 11)
            );
    }

    public function test_area_filter_only_returns_instructors_in_that_area(): void
    {
        $other = User::factory()->create(['role' => 'instructor']);
        $other->assignRole('instructor');
        $other->instructorProfile()->create([
            'level' => InstructorLevel::Level1,
            'status' => 'active',
            'rate_per_hour' => 600,
            'area' => 'Pilar',
            'latitude' => 9.8500,
            'longitude' => 126.1000,
        ]);

        Certificate::factory()->create([
            'user_id' => $other->id,
            'type' => CertificateType::SISA->value,
            'status' => CertificateStatus::Verified->value,
            'verified_at' => now()->subMonth(),
            'expires_at' => now()->addYear(),
        ]);

        $this->get(route('instructors.search', ['area' => 'Pilar']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('instructors.data', 1)
                ->where('instructors.data.0.id', $other->id)
                ->where('instructors.data.0.instructor_profile.area', 'Pilar')
            );
    }

    public function test_price_sort_keeps_the_map_and_rating_fields(): void
    {
        $this->get(route('instructors.search', ['sort_by' => 'price_low']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('instructors.data', 1)
                ->where('instructors.data.0.average_rating', 0)
            );
    }

    public function test_guest_can_view_the_public_profile_and_location(): void
    {
        $this->get(route('instructors.profile', $this->instructor))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Instructors/Profile')
                ->where('instructor.instructor_profile.area', 'General Luna')
                ->where('instructor.instructor_profile.latitude', '9.8119000')
                ->has('weeklyAvailability', 7)
            );
    }

    public function test_instructor_can_save_a_directory_location(): void
    {
        $this->actingAs($this->instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => $this->instructor->name,
                'bio' => 'Updated bio',
                'rate_per_hour' => 1200,
                'area' => 'Cloud 9',
                'meeting_point' => 'Tower by the break',
                'latitude' => 9.8089,
                'longitude' => 126.1525,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $profile = $this->instructor->instructorProfile->fresh();

        $this->assertSame('Cloud 9', $profile->area);
        $this->assertSame('Tower by the break', $profile->meeting_point);
        $this->assertSame('9.8089000', $profile->getRawOriginal('latitude'));
        $this->assertSame('126.1525000', $profile->getRawOriginal('longitude'));
    }
}
