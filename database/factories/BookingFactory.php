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

        // student_count defaults to 1 rather than being derived from a random
        // level. The level chosen here governs *pricing only* and is unrelated
        // to the instructor the factory attaches, so deriving a count from it
        // produced impossible bookings: a Level 2 instructor (capacity 1) could
        // be handed 5 students. Any test that then ran a capacity check failed
        // for reasons it was not asserting, roughly one run in four.
        //
        // Use the withStudents() state when a test genuinely needs a group.
        $studentCount = 1;
        $totalAmount = $rates[$level] * $hours * $studentCount;

        return [
            'student_id' => User::factory()->student(),
            'instructor_id' => User::factory()->instructor(),
            'surf_spot_id' => SurfSpot::factory(),
            'date' => fake()->dateTimeBetween('+1 day', '+30 days')->format('Y-m-d'),
            'time_period' => fake()->randomElement([TimePeriod::Morning->value, TimePeriod::Afternoon->value]),
            'skill_level' => SkillLevel::Beginner->value,
            'student_age' => fake()->numberBetween(13, 50),
            'student_count' => $studentCount,
            'status' => BookingStatus::Pending->value,
            'lesson_price' => $totalAmount,
            'service_fee_percent' => 7.00,
            'service_fee_amount' => 0.00,
            'total_amount' => $totalAmount,
            'payment_status' => PaymentStatus::Pending->value,
            'notes' => fake()->optional()->sentence(),
            'cancelled_at' => null,
            'completed_at' => null,
        ];
    }

    /**
     * A group booking. Pass the instructor's real capacity so the count is
     * legal for that level (1 -> 2 students, 2 -> 1, 3 -> up to 5).
     */
    public function withStudents(int $count, float $ratePerHour = 800.00): static
    {
        return $this->state(fn (array $attributes) => [
            'student_count' => $count,
            'total_amount' => $ratePerHour * 2 * $count,
        ]);
    }

    public function withServiceFee(float $lessonPrice = 1200.00, float $feePercent = 7.00): static
    {
        $feeAmount = round($lessonPrice * ($feePercent / 100), 2);

        return $this->state(fn (array $attributes) => [
            'lesson_price' => $lessonPrice,
            'service_fee_percent' => $feePercent,
            'service_fee_amount' => $feeAmount,
            'total_amount' => round($lessonPrice + $feeAmount, 2),
        ]);
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
