<?php

namespace Database\Factories;

use App\Enums\SkillLevel;
use App\Models\Booking;
use App\Models\BookingParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BookingParticipant>
 */
class BookingParticipantFactory extends Factory
{
    protected $model = BookingParticipant::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'role' => BookingParticipant::ROLE_PRIMARY,
            'full_name' => fake()->name(),
            'age' => fake()->numberBetween(18, 50),
            'height' => fake()->numberBetween(150, 190),
            'weight' => fake()->numberBetween(50, 95),
            'skill_level' => SkillLevel::Beginner->value,
            'has_board' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['role' => BookingParticipant::ROLE_PRIMARY]);
    }

    public function companion(): static
    {
        return $this->state(fn () => ['role' => BookingParticipant::ROLE_COMPANION]);
    }

    public function minor(): static
    {
        return $this->state(fn () => ['age' => fake()->numberBetween(5, 12)]);
    }
}
