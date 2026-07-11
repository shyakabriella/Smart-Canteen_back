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
        Schema::create('order_qr_codes', function (Blueprint $table) {
            $table->id();

            // Order relationship
            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            // Customer/user who owns this QR code
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // QR identity
            $table->string('qr_code_number')->unique();
            $table->string('qr_token')->unique();

            // Data to encode in QR code
            $table->longText('qr_payload')->nullable();

            // Optional saved QR image path if you generate QR image later
            $table->string('qr_image')->nullable();

            // active / used / expired / cancelled
            $table->string('status')->default('active');

            // Expiry and usage tracking
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();

            // Staff who scanned/used QR
            $table->foreignId('scanned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('scanned_at')->nullable();

            // Cancellation tracking
            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();

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
            $table->index(['user_id', 'status']);
            $table->index(['qr_code_number']);
            $table->index(['qr_token']);
            $table->index(['expires_at']);
            $table->index(['used_at']);
            $table->index(['scanned_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_qr_codes');
    }
};