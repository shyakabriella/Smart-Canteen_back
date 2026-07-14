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
        Schema::create('canteen_tables', function (Blueprint $table) {
            $table->id();

            $table->string('table_number', 50)->unique();
            $table->string('name', 150);
            $table->string('location', 150)->nullable();

            $table->unsignedSmallInteger('capacity')
                ->default(4);

            $table->string('status', 30)
                ->default('available')
                ->index();

            $table->text('description')->nullable();

            /*
             * Every table receives a different secure token.
             * This token is encoded inside the QR code.
             */
            $table->uuid('qr_token')->unique();

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

            $table->index('location');
            $table->index(['status', 'location']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canteen_tables');
    }
};