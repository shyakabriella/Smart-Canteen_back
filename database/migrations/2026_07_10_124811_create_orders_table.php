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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Customer/user who made the order
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Unique order number
            $table->string('order_number')->unique();

            // Order type: pickup, dine_in, takeaway
            $table->string('order_type')->default('pickup');

            // Money details
            $table->decimal('subtotal_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);

            // Payment details
            $table->string('payment_method')->default('wallet');
            $table->string('payment_status')->default('pending');

            // Order status
            $table->string('order_status')->default('pending');

            // Pickup status
            $table->string('pickup_status')->default('pending');

            // Notes
            $table->text('customer_notes')->nullable();
            $table->text('staff_notes')->nullable();

            // Order timestamps
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Confirmation information
            $table->foreignId('confirmed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('confirmed_at')->nullable();

            // Ready information
            $table->foreignId('ready_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('ready_at')->nullable();

            // Completion / collection information
            $table->foreignId('completed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('completed_at')->nullable();

            // Cancellation information
            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'order_status']);
            $table->index(['payment_status']);
            $table->index(['pickup_status']);
            $table->index(['ordered_at']);
            $table->index(['paid_at']);
            $table->index(['confirmed_at']);
            $table->index(['ready_at']);
            $table->index(['completed_at']);
            $table->index(['cancelled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};