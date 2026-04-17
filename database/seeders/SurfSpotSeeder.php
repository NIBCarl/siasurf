<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SurfSpot;
use App\Enums\SkillLevel;

class SurfSpotSeeder extends Seeder
{
    public function run(): void
    {
        $spots = [
            [
                'name' => 'Cloud 9',
                'description' => 'World-famous reef break known for thick, hollow barrels. One of the best waves in the world, but only for experienced surfers.',
                'difficulty' => SkillLevel::Advanced->value,
                'latitude' => 9.8075,
                'longitude' => 126.1636,
                'is_active' => true,
            ],
            [
                'name' => 'Quicksilver',
                'description' => 'Gentle beach break perfect for beginners. Sandy bottom with small, mellow waves ideal for learning.',
                'difficulty' => SkillLevel::Beginner->value,
                'latitude' => 9.8050,
                'longitude' => 126.1600,
                'is_active' => true,
            ],
            [
                'name' => 'Jacking Horse',
                'description' => 'Reef break with consistent waves. Good for intermediate surfers looking to progress from beach breaks.',
                'difficulty' => SkillLevel::Intermediate->value,
                'latitude' => 9.8100,
                'longitude' => 126.1650,
                'is_active' => true,
            ],
            [
                'name' => 'Cemetery',
                'description' => 'Beginner-friendly spot near the cemetery with small waves and sandy bottom. Great for first-timers.',
                'difficulty' => SkillLevel::Beginner->value,
                'latitude' => 9.7950,
                'longitude' => 126.1550,
                'is_active' => true,
            ],
            [
                'name' => 'Daku Reef',
                'description' => 'Reef break with multiple peaks. Offers both left and right-hand waves suitable for intermediate surfers.',
                'difficulty' => SkillLevel::Intermediate->value,
                'latitude' => 9.8150,
                'longitude' => 126.1700,
                'is_active' => true,
            ],
            [
                'name' => 'Rock Island',
                'description' => 'Advanced reef break with fast, powerful waves. Requires experience and confidence in heavy surf.',
                'difficulty' => SkillLevel::Advanced->value,
                'latitude' => 9.8200,
                'longitude' => 126.1750,
                'is_active' => true,
            ],
            [
                'name' => 'Tuazon Point',
                'description' => 'Intermediate reef break with consistent swells. Good for surfers looking to improve their skills.',
                'difficulty' => SkillLevel::Intermediate->value,
                'latitude' => 9.8250,
                'longitude' => 126.1800,
                'is_active' => true,
            ],
            [
                'name' => 'Pacifico Beach',
                'description' => 'Long stretch of beach with various breaks. Beginner-friendly sections available.',
                'difficulty' => SkillLevel::Beginner->value,
                'latitude' => 9.8500,
                'longitude' => 126.1900,
                'is_active' => true,
            ],
        ];

        foreach ($spots as $spot) {
            SurfSpot::create($spot);
        }
    }
}
