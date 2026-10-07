<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portafolio: los procedimientos CUPS que ofrece cada sede y cuánto dura cada atención.
     */
    public function up(): void
    {
        Schema::create('portafolio_servicios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $table->foreignId('cups_id')->constrained('cups')->restrictOnDelete();
            $table->unsignedSmallInteger('duracion_minutos')->comment('Duración de la atención para programar la agenda');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['sede_id', 'cups_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portafolio_servicios');
    }
};
