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
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();

            // Request identity
            $table->string('request_number')->unique();

            // Supplier is nullable because request can be created before choosing supplier
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('suppliers')
                ->nullOnDelete();

            // Stock and food item
            $table->foreignId('inventory_stock_id')
                ->constrained('inventory_stocks')
                ->cascadeOnDelete();

            $table->foreignId('food_item_id')
                ->constrained('food_items')
                ->cascadeOnDelete();

            // Optional low-stock alert that caused this request
            $table->foreignId('low_stock_alert_id')
                ->nullable()
                ->constrained('low_stock_alerts')
                ->nullOnDelete();

            // Quantities
            $table->integer('quantity_requested')->default(1);
            $table->integer('quantity_approved')->default(0);
            $table->integer('quantity_received')->default(0);

            // Cost estimation and receiving cost
            $table->decimal('estimated_unit_cost', 12, 2)->nullable();
            $table->decimal('estimated_total_cost', 12, 2)->nullable();
            $table->decimal('received_unit_cost', 12, 2)->nullable();
            $table->decimal('received_total_cost', 12, 2)->default(0);

            // pending / approved / rejected / ordered / partially_received / received / cancelled
            $table->string('status')->default('pending');

            // Request reason and notes
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();

            // Request tracking
            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('requested_at')->nullable();

            // Approval tracking
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('approved_at')->nullable();

            // Rejection tracking
            $table->foreignId('rejected_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Ordered tracking
            $table->foreignId('ordered_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('ordered_at')->nullable();
            $table->string('supplier_reference')->nullable();

            // Received tracking
            $table->foreignId('received_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('received_at')->nullable();

            // Cancellation tracking
            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

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

            $table->index(['request_number']);
            $table->index(['supplier_id']);
            $table->index(['inventory_stock_id']);
            $table->index(['food_item_id']);
            $table->index(['low_stock_alert_id']);
            $table->index(['status']);
            $table->index(['requested_by']);
            $table->index(['requested_at']);
            $table->index(['approved_at']);
            $table->index(['ordered_at']);
            $table->index(['received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};