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
        Schema::create('sales_reports', function (Blueprint $table) {
            $table->id();

            // Report identity
            $table->string('report_number')->unique();
            $table->string('title')->nullable();

            // daily / weekly / monthly / custom
            $table->string('report_type')->default('daily');

            // Report period
            $table->date('period_start');
            $table->date('period_end');

            // Order counts
            $table->integer('total_orders')->default(0);
            $table->integer('pending_orders')->default(0);
            $table->integer('confirmed_orders')->default(0);
            $table->integer('preparing_orders')->default(0);
            $table->integer('ready_orders')->default(0);
            $table->integer('completed_orders')->default(0);
            $table->integer('cancelled_orders')->default(0);

            // Payment counts
            $table->integer('paid_orders')->default(0);
            $table->integer('refunded_orders')->default(0);
            $table->integer('unpaid_orders')->default(0);

            // Sales totals
            $table->decimal('gross_sales', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->decimal('net_sales', 12, 2)->default(0);

            // Payment method totals
            $table->decimal('wallet_sales', 12, 2)->default(0);
            $table->decimal('cash_sales', 12, 2)->default(0);
            $table->decimal('mobile_money_sales', 12, 2)->default(0);

            // Item statistics
            $table->integer('total_items_sold')->default(0);
            $table->integer('total_quantity_sold')->default(0);

            // Average order value
            $table->decimal('average_order_value', 12, 2)->default(0);

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
        Schema::dropIfExists('sales_reports');
    }
};