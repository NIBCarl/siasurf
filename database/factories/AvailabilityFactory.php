<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Availability;
use App\Models\User;
use App\Enums\DayOfWeek;
use App\Enums\TimePeriod;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Availability>
 */
class AvailabilityFactory extends Factory
{
    protected $model = Availability::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->instructor(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'time_period' => fake()->randomElement([TimePeriod::Morning->value, TimePeriod::Afternoon->value]),
            'is_available' => true,
            'specific_date' => null,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_available' => false,
        ]);
    }

    public function morning(): static
    {
        return $this->state(fn (array $attributes) => [
            'time_period' => TimePeriod::Morning->value,
        ]);
    }

    public function afternoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'time_period' => TimePeriod::Afternoon->value,
        ]);
    }

    public function forSpecificDate($date): static
    {
        return $this->state(fn (array $attributes) => [
            'specific_date' => $date,
            'day_of_week' => null,
        ]);
    }
}
