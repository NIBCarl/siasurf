<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Payment;
use App\Models\Booking;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $method = fake()->randomElement([
            PaymentMethod::GCash->value,
            PaymentMethod::Cash->value,
        ]);

        return [
            'booking_id' => Booking::factory(),
            'amount' => fake()->randomFloat(2, 600, 5000),
            'payment_method' => $method,
            'transaction_id' => $method === PaymentMethod::GCash->value ? 'gcash_' . fake()->uuid() : null,
            'status' => PaymentStatus::Pending->value,
            'paid_at' => null,
            'refunded_at' => null,
            'notes' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Completed->value,
            'paid_at' => now()->subDays(fake()->numberBetween(1, 30)),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Failed->value,
            'notes' => 'Payment failed: ' . fake()->sentence(),
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Refunded->value,
            'refunded_at' => now()->subDays(fake()->numberBetween(1, 10)),
        ]);
    }

    public function gcash(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => PaymentMethod::GCash->value,
            'transaction_id' => 'gcash_' . fake()->uuid(),
        ]);
    }

    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => PaymentMethod::Cash->value,
            'transaction_id' => null,
        ]);
    }
}
