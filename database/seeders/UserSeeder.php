<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Default Admin User
        |--------------------------------------------------------------------------
        |
        | This admin will login to the dashboard and manage:
        | - users
        | - food items
        | - stock
        | - wallet top-ups
        | - orders
        | - QR pickup verification
        | - reports
        |
        */

        User::updateOrCreate(
            ['email' => 'admin@smartcanteen.com'],
            [
                'name' => 'System Administrator',
                'phone' => '0780000000',
                'password' => 'password',

                // Current role from our User model
                'role' => User::ROLE_ADMIN,
                'status' => User::STATUS_ACTIVE,

                // Wallet
                'wallet_balance' => 0,

                // User identification
                'user_code' => 'ADM-' . strtoupper(Str::random(8)),
                'qr_code' => null,

                // Device info
                'device_id' => null,
                'device_name' => null,
                'device_type' => 'web',
                'device_token' => null,

                // Profile and tracking
                'profile_photo' => null,
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'last_login_at' => null,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Optional Staff User
        |--------------------------------------------------------------------------
        |
        | This user can be used for testing QR code scanning and order pickup.
        |
        */

        User::updateOrCreate(
            ['email' => 'staff@smartcanteen.com'],
            [
                'name' => 'Canteen Staff',
                'phone' => '0780000001',
                'password' => 'password',

                'role' => User::ROLE_STAFF,
                'status' => User::STATUS_ACTIVE,

                'wallet_balance' => 0,

                'user_code' => 'STF-' . strtoupper(Str::random(8)),
                'qr_code' => null,

                'device_id' => null,
                'device_name' => null,
                'device_type' => 'web',
                'device_token' => null,

                'profile_photo' => null,
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'last_login_at' => null,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Optional Student Test User
        |--------------------------------------------------------------------------
        |
        | This user can test mobile app ordering and wallet payment.
        |
        */

        User::updateOrCreate(
            ['email' => 'student@smartcanteen.com'],
            [
                'name' => 'Test Student',
                'phone' => '0780000002',
                'password' => 'password',

                'role' => User::ROLE_STUDENT,
                'status' => User::STATUS_ACTIVE,

                // Give test balance for buying food
                'wallet_balance' => 5000,

                'user_code' => 'STD-' . strtoupper(Str::random(8)),
                'qr_code' => null,

                'device_id' => null,
                'device_name' => null,
                'device_type' => 'android',
                'device_token' => null,

                'profile_photo' => null,
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'last_login_at' => null,
            ]
        );
    }
}