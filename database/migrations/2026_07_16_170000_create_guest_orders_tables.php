<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_orders', function (Blueprint $table) {
            $table->id();

            $table->string('order_number', 100)
                ->unique();

            $table->string('public_token', 64)
                ->unique();

            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 50)
                ->index();

            $table->text('delivery_location');

            $table->string(
                'preferred_delivery_time',
                100
            )->nullable();

            $table->decimal(
                'subtotal_amount',
                15,
                2
            )->default(0);

            $table->decimal(
                'discount_amount',
                15,
                2
            )->default(0);

            $table->decimal(
                'tax_amount',
                15,
                2
            )->default(0);

            $table->decimal(
                'total_amount',
                15,
                2
            )->default(0);

            $table->decimal(
                'paid_amount',
                15,
                2
            )->default(0);

            $table->string('payment_method', 50)
                ->default('pay_on_delivery');

            $table->string('payment_status', 50)
                ->default('pending')
                ->index();

            $table->string('order_status', 50)
                ->default('pending')
                ->index();

            $table->string('delivery_status', 50)
                ->default('pending')
                ->index();

            $table->text('customer_notes')
                ->nullable();

            $table->text('staff_notes')
                ->nullable();

            $table->foreignId('confirmed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('confirmed_at')
                ->nullable();

            $table->foreignId('ready_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('ready_at')
                ->nullable();

            $table->foreignId('completed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('completed_at')
                ->nullable();

            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('cancelled_at')
                ->nullable();

            $table->text('cancellation_reason')
                ->nullable();

            $table->timestamps();
        });

        Schema::create(
            'guest_order_items',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'guest_order_id'
                )
                    ->constrained(
                        'guest_orders'
                    )
                    ->cascadeOnDelete();

                $table->foreignId(
                    'food_item_id'
                )
                    ->nullable()
                    ->constrained(
                        'food_items'
                    )
                    ->nullOnDelete();

                $table->string('food_name');
                $table->string(
                    'food_sku',
                    100
                )->nullable();

                $table->string(
                    'unit',
                    50
                )->nullable();

                $table->unsignedInteger(
                    'quantity'
                );

                $table->decimal(
                    'unit_price',
                    15,
                    2
                );

                $table->decimal(
                    'subtotal_amount',
                    15,
                    2
                );

                $table->decimal(
                    'total_amount',
                    15,
                    2
                );

                $table->string(
                    'item_status',
                    50
                )->default('pending');

                $table->text('notes')
                    ->nullable();

                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'guest_order_items'
        );

        Schema::dropIfExists(
            'guest_orders'
        );
    }
};
