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
        Schema::create('low_stock_alerts', function (Blueprint $table) {
            $table->id();

            // Related stock and food item
            $table->foreignId('inventory_stock_id')
                ->constrained('inventory_stocks')
                ->cascadeOnDelete();

            $table->foreignId('food_item_id')
                ->constrained('food_items')
                ->cascadeOnDelete();

            // Alert identity
            $table->string('alert_number')->unique();

            // low_stock / out_of_stock / restock_required
            $table->string('alert_type')->default('low_stock');

            // low / medium / high / critical
            $table->string('severity')->default('medium');

            // Stock snapshot
            $table->integer('current_quantity')->default(0);
            $table->integer('threshold_quantity')->default(0);

            // active / resolved / dismissed
            $table->string('status')->default('active');

            // Alert message
            $table->string('message')->nullable();
            $table->text('notes')->nullable();

            // Resolution tracking
            $table->foreignId('resolved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();

            // Dismiss tracking
            $table->foreignId('dismissed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('dismissed_at')->nullable();

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

            $table->index(['inventory_stock_id']);
            $table->index(['food_item_id']);
            $table->index(['alert_number']);
            $table->index(['alert_type']);
            $table->index(['severity']);
            $table->index(['status']);
            $table->index(['resolved_at']);
            $table->index(['dismissed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('low_stock_alerts');
    }
};