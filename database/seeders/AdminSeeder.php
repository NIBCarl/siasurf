<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::create([
            'name' => 'SiaSurf Admin',
            'email' => 'siasurfadmin@gmail.com',
            'password' => Hash::make(env('ADMIN_DEFAULT_PASSWORD', 'siasurfadmin')),
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $admin->assignRole('admin');
    }
}
