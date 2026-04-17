<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Booking;
use App\Models\User;
use App\Models\SurfSpot;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\SkillLevel;
use App\Enums\TimePeriod;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $level = fake()->randomElement([1, 2, 3]);
        $rates = [1 => 600.00, 2 => 800.00, 3 => 1500.00];
        $hours = 2;
        $studentCount = fake()->numberBetween(1, $level === 2 ? 1 : ($level === 1 ? 2 : 5));
        $totalAmount = $rates[$level] * $hours * $studentCount;

        return [
            'student_id' => User::factory()->student(),
            'instructor_id' => User::factory()->instructor(),
            'surf_spot_id' => SurfSpot::factory(),
            'date' => fake()->dateTimeBetween('+1 day', '+30 days')->format('Y-m-d'),
            'time_period' => fake()->randomElement([TimePeriod::Morning->value, TimePeriod::Afternoon->value]),
            'skill_level' => fake()->randomElement([
                SkillLevel::Beginner->value,
                SkillLevel::Intermediate->value,
                SkillLevel::Advanced->value,
            ]),
            'student_age' => fake()->numberBetween(13, 50),
            'student_count' => $studentCount,
            'status' => BookingStatus::Pending->value,
            'total_amount' => $totalAmount,
            'payment_status' => PaymentStatus::Pending->value,
            'notes' => fake()->optional()->sentence(),
            'cancelled_at' => null,
            'completed_at' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Confirmed->value,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Completed->value,
            'completed_at' => now()->subDays(fake()->numberBetween(1, 30)),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Cancelled->value,
            'cancelled_at' => now()->subDays(fake()->numberBetween(1, 10)),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => PaymentStatus::Completed->value,
        ]);
    }

    public function forMinor(): static
    {
        return $this->state(fn (array $attributes) => [
            'student_age' => fake()->numberBetween(5, 12),
        ]);
    }
}
