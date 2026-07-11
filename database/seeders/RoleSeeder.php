<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Smart Canteen System Roles
        |--------------------------------------------------------------------------
        |
        | admin   = system administrator / canteen manager
        | staff   = canteen worker who scans QR codes and confirms food pickup
        | student = normal user who orders food from mobile app
        |
        | Note:
        | If your project does not have a roles table yet, this seeder will not fail.
        |
        */

        if (!Schema::hasTable('roles')) {
            return;
        }

        $roles = [
            [
                'name' => 'admin',
                'display_name' => 'Administrator',
                'description' => 'Manages the whole system, users, food items, wallet top-ups, inventory, and reports.',
            ],
            [
                'name' => 'staff',
                'display_name' => 'Canteen Staff',
                'description' => 'Scans QR codes, verifies paid orders, gives food to users, and marks orders as collected.',
            ],
            [
                'name' => 'student',
                'display_name' => 'Student/User',
                'description' => 'Uses the mobile app to order food, pay with wallet, and collect food using QR code.',
            ],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name']],
                [
                    'display_name' => $role['display_name'],
                    'description' => $role['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}