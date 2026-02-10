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
        Schema::create('user_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->integer('total_devices')->default(0);
            $table->integer('successful_devices')->default(0);
            $table->integer('devices_with_errors')->default(0);
            $table->integer('total_users')->default(0);
            $table->integer('new_users')->default(0);
            $table->integer('updated_users')->default(0);
            $table->integer('duration_ms')->default(0);
            $table->string('status', 20)->default('pending'); // pending, completed, error
            $table->text('error_message')->nullable();
            $table->string('trigger', 50)->default('manual'); // manual, scheduled, api
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_sync_logs');
    }
};