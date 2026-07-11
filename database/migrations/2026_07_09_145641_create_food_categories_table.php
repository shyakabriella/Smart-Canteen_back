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
        Schema::create('food_categories', function (Blueprint $table) {
            $table->id();

            // Basic category information
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Optional image/icon for mobile app or dashboard
            $table->string('image')->nullable();

            // active / inactive
            $table->string('status')->default('active');

            // For ordering categories in the app/dashboard
            $table->integer('sort_order')->default(0);

            // User relationship
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('food_categories');
    }
};