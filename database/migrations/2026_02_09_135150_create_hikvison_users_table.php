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
        Schema::create('hikvision_users', function (Blueprint $table) {
            $table->id();
            $table->string('employee_no', 50)->unique()->index();
            $table->string('nombre');
            $table->string('tipo_usuario', 50)->nullable();
            $table->date('fecha_creacion')->nullable();
            $table->date('fecha_modificacion')->nullable();
            $table->string('estado', 20)->default('Activo');
            $table->string('departamento')->nullable();
            $table->string('genero', 20)->nullable();
            $table->text('foto_path')->nullable();
            $table->string('device_ip', 45)->nullable();
            $table->timestamps();

            // Índices para búsquedas frecuentes
            $table->index('estado');
            $table->index('departamento');
            $table->index('device_ip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hikvision_users');
    }
};