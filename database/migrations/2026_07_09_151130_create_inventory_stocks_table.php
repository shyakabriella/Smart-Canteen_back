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
        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();

            // Each food item should have one stock record
            $table->foreignId('food_item_id')
                ->constrained('food_items')
                ->cascadeOnDelete();

            // Stock quantities
            $table->integer('quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);

            // Minimum stock before system shows low-stock alert
            $table->integer('low_stock_quantity')->default(5);

            // Stock location example: Main Canteen, Store Room, Fridge
            $table->string('location')->nullable();

            // active / inactive
            $table->string('status')->default('active');

            // Last time stock was updated
            $table->timestamp('last_restocked_at')->nullable();

            // User relationship
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('food_item_id');
            $table->index(['status']);
            $table->index(['quantity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_stocks');
    }
};