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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            // Order relationship
            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            // Food item relationship
            $table->foreignId('food_item_id')
                ->constrained('food_items')
                ->cascadeOnDelete();

            // Snapshot of food item information at order time
            $table->string('food_name');
            $table->string('food_sku')->nullable();
            $table->string('unit')->default('piece');

            // Quantity and price
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();

            // Money calculations
            $table->decimal('subtotal_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            // pending / confirmed / prepared / collected / cancelled
            $table->string('item_status')->default('confirmed');

            // Notes
            $table->text('notes')->nullable();

            // User tracking
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['order_id', 'food_item_id']);
            $table->index(['food_item_id']);
            $table->index(['item_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};