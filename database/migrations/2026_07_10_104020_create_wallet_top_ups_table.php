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
        Schema::create('wallet_top_ups', function (Blueprint $table) {
            $table->id();

            // User whose wallet will receive the money
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Top-up information
            $table->string('top_up_number')->unique();
            $table->decimal('amount', 12, 2);

            // Payment information
            $table->string('payment_method')->default('cash');
            $table->string('payment_reference')->nullable();
            $table->string('payment_proof')->nullable();

            // pending / approved / rejected / cancelled
            $table->string('status')->default('pending');

            // Notes
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();

            // Who requested the top-up
            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('requested_at')->nullable();

            // Approval information
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('approved_at')->nullable();

            // Rejection information
            $table->foreignId('rejected_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('rejected_at')->nullable();

            // Cancellation information
            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['top_up_number']);
            $table->index(['payment_method']);
            $table->index(['requested_at']);
            $table->index(['approved_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_top_ups');
    }
};