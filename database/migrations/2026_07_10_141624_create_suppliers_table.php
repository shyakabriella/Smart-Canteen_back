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
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            // Supplier identity
            $table->string('supplier_code')->unique();
            $table->string('name')->unique();

            // Contact information
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->string('alternate_phone')->nullable();

            // Address information
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->default('Rwanda');

            // Business information
            $table->string('tin_number')->nullable();
            $table->string('payment_terms')->nullable();
            $table->decimal('opening_balance', 12, 2)->default(0);

            // active / inactive
            $table->string('status')->default('active');

            // Notes
            $table->text('notes')->nullable();

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

            $table->index(['status']);
            $table->index(['supplier_code']);
            $table->index(['city']);
            $table->index(['country']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};