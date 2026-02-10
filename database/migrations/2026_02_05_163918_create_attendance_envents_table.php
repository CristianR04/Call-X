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
        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->string('documento', 50)->index();
            $table->string('nombre');
            $table->date('fecha')->index();
            $table->time('hora_entrada')->nullable();
            $table->time('hora_salida')->nullable();
            $table->time('hora_salida_almuerzo')->nullable();
            $table->time('hora_entrada_almuerzo')->nullable();
            $table->string('dispositivo_ip')->nullable();
            $table->string('campaña')->nullable()->index();
            $table->string('imagen')->nullable();
            $table->timestamps();
            
            // Índice compuesto para búsquedas por empleado y fecha
            $table->unique(['documento', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_events');
    }
};