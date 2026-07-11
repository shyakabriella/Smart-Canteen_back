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
        Schema::create('qr_scan_logs', function (Blueprint $table) {
            $table->id();

            // QR code relationship, nullable because invalid QR scans may not match any QR record
            $table->foreignId('order_qr_code_id')
                ->nullable()
                ->constrained('order_qr_codes')
                ->nullOnDelete();

            // Order relationship, nullable for invalid scans
            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->nullOnDelete();

            // Customer/user who owns the QR code
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Staff/admin who scanned the QR code
            $table->foreignId('scanned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Scan information
            $table->string('scan_action')->default('verify'); 
            // verify / collect

            $table->string('scan_status')->default('failed'); 
            // valid / success / failed / expired / used / cancelled / unpaid / invalid

            // QR values scanned
            $table->string('qr_code_number')->nullable();
            $table->string('qr_token')->nullable();
            $table->longText('scanned_payload')->nullable();

            // Result message
            $table->string('message')->nullable();
            $table->text('failure_reason')->nullable();

            // Device/browser information
            $table->string('device_name')->nullable();
            $table->string('device_type')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Optional scan location
            $table->string('location')->nullable();

            $table->timestamp('scanned_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['order_qr_code_id']);
            $table->index(['order_id']);
            $table->index(['user_id']);
            $table->index(['scanned_by']);
            $table->index(['scan_action']);
            $table->index(['scan_status']);
            $table->index(['qr_code_number']);
            $table->index(['scanned_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qr_scan_logs');
    }
};