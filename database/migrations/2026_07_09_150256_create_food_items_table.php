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
        Schema::create('food_items', function (Blueprint $table) {
            $table->id();

            // Category relationship
            $table->foreignId('food_category_id')
                ->constrained('food_categories')
                ->cascadeOnDelete();

            // Basic food information
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->nullable()->unique();

            $table->text('description')->nullable();
            $table->string('image')->nullable();

            // Price information
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();

            // Unit example: plate, piece, bottle, cup, packet
            $table->string('unit')->default('piece');

            // Low stock level for alert later
            $table->integer('low_stock_quantity')->default(5);

            // Food status
            $table->string('status')->default('active');
            $table->boolean('is_available')->default(true);

            // Optional extra information
            $table->integer('preparation_time_minutes')->nullable();
            $table->integer('sort_order')->default(0);

            // User relationship
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['food_category_id', 'status']);
            $table->index(['is_available', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('food_items');
    }
};