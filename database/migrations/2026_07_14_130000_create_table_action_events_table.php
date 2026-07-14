<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_action_events', function (Blueprint $table) {
            $table->id();

            /*
             * canteen_tables.id is created using $table->id(),
             * therefore this must be an unsigned BIGINT foreign key.
             */
            $table->foreignId('canteen_table_id')
                ->constrained('canteen_tables')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->enum('action', [
                'order',
                'call',
                'pay',
                'cancel',
            ]);

            $table->enum('status', [
                'pending',
                'acknowledged',
                'completed',
                'cancelled',
            ])->default('pending');

            $table->string('message')->nullable();

            $table->string('source_ip', 45)->nullable();

            $table->text('user_agent')->nullable();

            $table->timestamp('occurred_at')
                ->useCurrent();

            $table->timestamp('handled_at')
                ->nullable();

            $table->timestamps();

            $table->index(
                [
                    'action',
                    'status',
                    'occurred_at',
                ],
                'table_action_events_action_status_index'
            );

            $table->index(
                [
                    'canteen_table_id',
                    'occurred_at',
                ],
                'table_action_events_table_date_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_action_events');
    }
};
