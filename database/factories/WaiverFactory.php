<?php

namespace Database\Factories;

use App\Enums\WaiverType;
use App\Models\Booking;
use App\Models\Waiver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Waiver>
 */
class WaiverFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Waiver::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $signedAt = fake()->dateTimeBetween('-1 year', 'now');
        
        return [
            'booking_id' => Booking::factory(),
            'type' => fake()->randomElement([WaiverType::Liability->value, WaiverType::ParentalConsent->value]),
            'signed_by' => fake()->name(),
            'signature_data' => 'data:image/png;base64,' . base64_encode(fake()->randomAscii()),
            'pdf_path' => 'waivers/waiver_' . fake()->unique()->numberBetween(1, 10000) . '.pdf',
            'signed_at' => $signedAt,
            'retention_until' => (clone $signedAt)->modify('+7 years'),
        ];
    }

    /**
     * Indicate the waiver is a liability waiver.
     */
    public function liability(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => WaiverType::Liability->value,
        ]);
    }

    /**
     * Indicate the waiver is a parental consent.
     */
    public function parentalConsent(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => WaiverType::ParentalConsent->value,
        ]);
    }

    /**
     * Indicate the waiver has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'retention_until' => fake()->dateTimeBetween('-5 years', '-1 day'),
        ]);
    }

    /**
     * Indicate the waiver is about to expire.
     */
    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'retention_until' => fake()->dateTimeBetween('now', '+30 days'),
        ]);
    }
}
