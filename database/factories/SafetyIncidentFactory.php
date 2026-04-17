<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\SafetyIncident;
use App\Models\Booking;
use App\Models\User;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentType;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SafetyIncident>
 */
class SafetyIncidentFactory extends Factory
{
    protected $model = SafetyIncident::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory()->completed()->optional(),
            'instructor_id' => User::factory()->instructor(),
            'reported_by' => User::factory()->admin(),
            'type' => fake()->randomElement([
                IncidentType::Injury->value,
                IncidentType::NearMiss->value,
                IncidentType::RuleViolation->value,
            ]),
            'severity' => fake()->randomElement([
                IncidentSeverity::Minor->value,
                IncidentSeverity::Major->value,
                IncidentSeverity::Critical->value,
            ]),
            'description' => fake()->paragraph(),
            'location' => fake()->optional()->address(),
            'resolved_at' => fake()->optional()->dateTimeBetween('-10 days', 'now'),
        ];
    }

    public function minor(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => IncidentSeverity::Minor->value,
        ]);
    }

    public function major(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => IncidentSeverity::Major->value,
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => IncidentSeverity::Critical->value,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'resolved_at' => now()->subDays(fake()->numberBetween(1, 10)),
        ]);
    }

    public function unresolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'resolved_at' => null,
        ]);
    }
}
