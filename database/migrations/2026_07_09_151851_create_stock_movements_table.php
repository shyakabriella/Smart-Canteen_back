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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

            // Stock and food item relationship
            $table->foreignId('inventory_stock_id')
                ->nullable()
                ->constrained('inventory_stocks')
                ->nullOnDelete();

            $table->foreignId('food_item_id')
                ->constrained('food_items')
                ->cascadeOnDelete();

            // Movement type: restock, sale, adjustment, damaged, expired, return
            $table->string('movement_type');

            // Quantity tracking
            $table->integer('quantity_before')->default(0);
            $table->integer('quantity_change')->default(0);
            $table->integer('quantity_after')->default(0);

            // Cost tracking, useful for reports later
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('total_cost', 12, 2)->nullable();

            // Optional reference for future modules like orders or purchase requests
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();

            // Explanation
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();

            // Date when movement happened
            $table->timestamp('movement_date')->nullable();

            // User relationship
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['food_item_id', 'movement_type']);
            $table->index(['inventory_stock_id']);
            $table->index(['movement_date']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};