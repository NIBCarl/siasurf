<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // 1. Roles and permissions must be first
            RolesAndPermissionsSeeder::class,
            
            // 2. Create admin user
            AdminSeeder::class,
            
            // 3. Create surf spots (needed for bookings)
            SurfSpotSeeder::class,
            
            // Optional: Uncomment for demo data
            // InstructorSeeder::class,
            // BookingSeeder::class,
        ]);
    }
}
