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
        Schema::create('inventory_reports', function (Blueprint $table) {
            $table->id();

            // Report identity
            $table->string('report_number')->unique();
            $table->string('title')->nullable();

            // current_stock / daily / weekly / monthly / custom
            $table->string('report_type')->default('current_stock');

            // Report period
            $table->date('period_start');
            $table->date('period_end');

            // Food item counts
            $table->integer('total_food_items')->default(0);
            $table->integer('active_food_items')->default(0);
            $table->integer('inactive_food_items')->default(0);
            $table->integer('available_food_items')->default(0);
            $table->integer('unavailable_food_items')->default(0);

            // Stock counts
            $table->integer('total_stock_records')->default(0);
            $table->integer('total_stock_quantity')->default(0);
            $table->integer('total_reserved_quantity')->default(0);
            $table->integer('total_available_quantity')->default(0);

            // Stock warning counts
            $table->integer('low_stock_items')->default(0);
            $table->integer('out_of_stock_items')->default(0);

            // Inventory value
            $table->decimal('total_stock_cost_value', 14, 2)->default(0);
            $table->decimal('total_stock_retail_value', 14, 2)->default(0);

            // Stock movement summary
            $table->integer('total_movements')->default(0);
            $table->integer('restock_quantity')->default(0);
            $table->integer('sales_quantity')->default(0);
            $table->integer('adjustment_quantity')->default(0);
            $table->integer('damaged_quantity')->default(0);
            $table->integer('expired_quantity')->default(0);
            $table->integer('return_quantity')->default(0);

            // draft / final / cancelled
            $table->string('status')->default('draft');

            // Extra JSON data for dashboard charts
            $table->json('report_data')->nullable();

            // Notes
            $table->text('notes')->nullable();

            // Generated tracking
            $table->foreignId('generated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('generated_at')->nullable();

            // Finalized tracking
            $table->foreignId('finalized_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('finalized_at')->nullable();

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

            $table->index(['report_number']);
            $table->index(['report_type']);
            $table->index(['period_start', 'period_end']);
            $table->index(['status']);
            $table->index(['generated_by']);
            $table->index(['generated_at']);
            $table->index(['finalized_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_reports');
    }
};