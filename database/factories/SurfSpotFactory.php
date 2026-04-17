<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\SurfSpot;
use App\Enums\SkillLevel;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SurfSpot>
 */
class SurfSpotFactory extends Factory
{
    protected $model = SurfSpot::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true) . ' Surf Spot',
            'description' => fake()->paragraph(),
            'difficulty' => fake()->randomElement([
                SkillLevel::Beginner->value,
                SkillLevel::Intermediate->value,
                SkillLevel::Advanced->value,
            ]),
            'latitude' => fake()->latitude(9.5, 10.0),
            'longitude' => fake()->longitude(125.5, 126.5),
            'is_active' => true,
        ];
    }

    public function beginner(): static
    {
        return $this->state(fn (array $attributes) => [
            'difficulty' => SkillLevel::Beginner->value,
        ]);
    }

    public function intermediate(): static
    {
        return $this->state(fn (array $attributes) => [
            'difficulty' => SkillLevel::Intermediate->value,
        ]);
    }

    public function advanced(): static
    {
        return $this->state(fn (array $attributes) => [
            'difficulty' => SkillLevel::Advanced->value,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
