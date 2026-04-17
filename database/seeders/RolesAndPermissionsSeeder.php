<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create roles
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $instructorRole = Role::firstOrCreate(['name' => 'instructor']);
        $studentRole = Role::firstOrCreate(['name' => 'student']);

        // Create permissions
        $permissions = [
            // Booking permissions
            'bookings.create',
            'bookings.view',
            'bookings.cancel',
            'bookings.manage',

            // Instructor permissions
            'instructors.verify',
            'instructors.manage',
            'instructors.suspend',

            // Incident permissions
            'incidents.create',
            'incidents.view',
            'incidents.manage',

            // Review permissions
            'reviews.create',
            'reviews.moderate',

            // Payment permissions
            'payments.view',
            'payments.refund',

            // Waiver permissions
            'waivers.generate',
            'waivers.view',

            // Analytics permissions
            'analytics.view',
            'reports.export',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Assign permissions to roles
        $adminRole->givePermissionTo(Permission::all());

        $instructorRole->givePermissionTo([
            'bookings.view',
            'bookings.cancel',
            'incidents.create',
            'incidents.view',
            'waivers.view',
        ]);

        $studentRole->givePermissionTo([
            'bookings.create',
            'bookings.view',
            'bookings.cancel',
            'reviews.create',
            'waivers.generate',
        ]);
    }
}
