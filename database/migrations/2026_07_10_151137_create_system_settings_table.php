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
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();

            // Setting identity
            $table->string('setting_key')->unique();
            $table->longText('setting_value')->nullable();

            // string / integer / decimal / boolean / json
            $table->string('value_type')->default('string');

            // general / wallet / qr / inventory / reports / notifications / security / system
            $table->string('setting_group')->default('general');

            // Display information
            $table->string('label')->nullable();
            $table->text('description')->nullable();

            // Public settings can be shown to mobile/frontend without exposing private rules
            $table->boolean('is_public')->default(false);

            // Some system default settings should not be deleted/edited by mistake
            $table->boolean('is_editable')->default(true);

            // active / inactive
            $table->string('status')->default('active');

            $table->integer('sort_order')->default(0);

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

            $table->index(['setting_key']);
            $table->index(['setting_group']);
            $table->index(['value_type']);
            $table->index(['is_public']);
            $table->index(['is_editable']);
            $table->index(['status']);
            $table->index(['sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};