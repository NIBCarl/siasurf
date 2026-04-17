<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\InstructorProfile;
use App\Models\User;
use App\Enums\InstructorLevel;
use App\Enums\InstructorStatus;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InstructorProfile>
 */
class InstructorProfileFactory extends Factory
{
    protected $model = InstructorProfile::class;

    public function definition(): array
    {
        $level = fake()->randomElement([1, 2, 3]);
        $rates = [1 => 600.00, 2 => 800.00, 3 => 1500.00];
        
        return [
            'user_id' => User::factory()->instructor(),
            'bio' => fake()->paragraph(),
            'level' => $level,
            'status' => InstructorStatus::Active->value,
            'rate_per_hour' => $rates[$level],
            'strike_count' => 0,
            'qr_code_path' => null,
            'suspended_until' => null,
        ];
    }

    public function level1(): static
    {
        return $this->state(fn (array $attributes) => [
            'level' => 1,
            'rate_per_hour' => 600.00,
        ]);
    }

    public function level2(): static
    {
        return $this->state(fn (array $attributes) => [
            'level' => 2,
            'rate_per_hour' => 800.00,
        ]);
    }

    public function level3(): static
    {
        return $this->state(fn (array $attributes) => [
            'level' => 3,
            'rate_per_hour' => 1500.00,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InstructorStatus::PendingVerification->value,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InstructorStatus::Suspended->value,
            'suspended_until' => now()->addMonth(),
        ]);
    }
}
