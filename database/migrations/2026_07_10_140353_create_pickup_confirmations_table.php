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
        Schema::create('pickup_confirmations', function (Blueprint $table) {
            $table->id();

            // One official pickup confirmation per order
            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            // QR code used for pickup, nullable for manual confirmation
            $table->foreignId('order_qr_code_id')
                ->nullable()
                ->constrained('order_qr_codes')
                ->nullOnDelete();

            // QR scan log used during confirmation, nullable for manual confirmation
            $table->foreignId('qr_scan_log_id')
                ->nullable()
                ->constrained('qr_scan_logs')
                ->nullOnDelete();

            // Customer/user who collected the order
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Confirmation identity
            $table->string('confirmation_number')->unique();

            // qr_scan / manual
            $table->string('confirmation_method')->default('qr_scan');

            // confirmed / cancelled
            $table->string('status')->default('confirmed');

            // Snapshot information
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('order_number')->nullable();
            $table->string('qr_code_number')->nullable();

            // Staff/admin who confirmed pickup
            $table->foreignId('confirmed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('confirmed_at')->nullable();

            // Cancellation tracking for wrong confirmation
            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            // Device/location information
            $table->string('device_name')->nullable();
            $table->string('device_type')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('location')->nullable();

            // Notes
            $table->text('notes')->nullable();

            // User tracking
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('order_id');
            $table->index(['order_qr_code_id']);
            $table->index(['qr_scan_log_id']);
            $table->index(['user_id']);
            $table->index(['confirmation_number']);
            $table->index(['confirmation_method']);
            $table->index(['status']);
            $table->index(['confirmed_by']);
            $table->index(['confirmed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pickup_confirmations');
    }
};