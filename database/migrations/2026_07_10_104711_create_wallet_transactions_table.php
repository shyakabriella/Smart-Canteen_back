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
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            // Wallet owner
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Optional link to wallet top-up
            $table->foreignId('wallet_top_up_id')
                ->nullable()
                ->constrained('wallet_top_ups')
                ->nullOnDelete();

            // Transaction identity
            $table->string('transaction_number')->unique();

            // credit = money added, debit = money removed
            $table->string('transaction_type');

            // top_up, order_payment, refund, manual_adjustment
            $table->string('source_type')->default('manual_adjustment');

            // Future reference support, example order id later
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference_number')->nullable();

            // Amount and balance tracking
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_before', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 2)->default(0);

            // completed, pending, failed, reversed
            $table->string('status')->default('completed');

            // Description
            $table->string('description')->nullable();
            $table->text('notes')->nullable();

            // Who processed this transaction
            $table->foreignId('processed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'transaction_type']);
            $table->index(['wallet_top_up_id']);
            $table->index(['source_type', 'source_id']);
            $table->index(['status']);
            $table->index(['processed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};