<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Basic profile
            $table->string('phone')->nullable()->unique()->after('email');

            // Role and account status
            $table->string('role')->default('student')->after('password');
            $table->string('status')->default('active')->after('role');

            // Wallet balance for food ordering
            $table->decimal('wallet_balance', 12, 2)->default(0)->after('status');

            // QR/user identification
            $table->string('user_code')->nullable()->unique()->after('wallet_balance');
            $table->string('qr_code')->nullable()->after('user_code');

            // Mobile device information
            $table->string('device_id')->nullable()->after('qr_code');
            $table->string('device_name')->nullable()->after('device_id');
            $table->string('device_type')->nullable()->after('device_name');
            $table->string('device_token')->nullable()->after('device_type');

            // Profile photo
            $table->string('profile_photo')->nullable()->after('device_token');

            // Verification and login tracking
            $table->timestamp('phone_verified_at')->nullable()->after('profile_photo');
            $table->timestamp('last_login_at')->nullable()->after('phone_verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'role',
                'status',
                'wallet_balance',
                'user_code',
                'qr_code',
                'device_id',
                'device_name',
                'device_type',
                'device_token',
                'profile_photo',
                'phone_verified_at',
                'last_login_at',
            ]);
        });
    }
};