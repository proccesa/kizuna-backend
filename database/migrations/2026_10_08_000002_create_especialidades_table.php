<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('especialidades', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 60)->comment('Identificador legible: medicina-general, cardiologia…');
            $table->string('nombre', 150);
            $table->string('descripcion', 255)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('codigo');
        });

        // Qué especialidades pueden atender cada procedimiento CUPS.
        Schema::create('cups_especialidad', function (Blueprint $table) {
            $table->foreignId('cups_id')->constrained('cups')->cascadeOnDelete();
            $table->foreignId('especialidad_id')->constrained('especialidades')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['cups_id', 'especialidad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cups_especialidad');
        Schema::dropIfExists('especialidades');
    }
};
