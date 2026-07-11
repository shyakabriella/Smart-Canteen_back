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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // User who performed the action
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Activity identity
            $table->string('log_number')->unique();

            // Module/action information
            $table->string('module')->nullable(); 
            // auth / orders / wallet / inventory / qr / reports / suppliers / system

            $table->string('action'); 
            // login / logout / create / update / delete / approve / reject / cancel / scan / generate

            // success / failed / warning
            $table->string('status')->default('success');

            // info / low / medium / high / critical
            $table->string('severity')->default('info');

            // Human-readable message
            $table->string('description')->nullable();

            // Polymorphic subject affected by this action
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // Store before/after values if needed
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // Extra metadata
            $table->json('metadata')->nullable();

            // Request/device information
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_name')->nullable();
            $table->string('device_type')->nullable();
            $table->string('request_method')->nullable();
            $table->string('request_url')->nullable();

            // Time of activity
            $table->timestamp('occurred_at')->nullable();

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

            $table->index(['user_id']);
            $table->index(['log_number']);
            $table->index(['module']);
            $table->index(['action']);
            $table->index(['status']);
            $table->index(['severity']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['occurred_at']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};