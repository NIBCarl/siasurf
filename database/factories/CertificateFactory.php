<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Certificate;
use App\Models\User;
use App\Enums\CertificateType;
use App\Enums\CertificateStatus;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Certificate>
 */
class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->instructor(),
            'type' => fake()->randomElement([
                CertificateType::BLS->value,
                CertificateType::WaSAR->value,
                CertificateType::SurfSkill->value,
                CertificateType::ISA->value,
                CertificateType::SISA->value,
            ]),
            'file_path' => 'certificates/' . fake()->uuid() . '.pdf',
            'status' => CertificateStatus::PendingVerification->value,
            'verified_at' => null,
            'expires_at' => fake()->optional()->dateTimeBetween('+1 year', '+3 years'),
            'admin_notes' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CertificateStatus::Verified->value,
            'verified_at' => now()->subDays(fake()->numberBetween(1, 365)),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CertificateStatus::Expired->value,
            'expires_at' => now()->subDays(fake()->numberBetween(1, 365)),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CertificateStatus::Rejected->value,
            'admin_notes' => fake()->sentence(),
        ]);
    }
}
