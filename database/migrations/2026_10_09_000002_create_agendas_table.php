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
        // Franjas semanales en las que un especialista atiende una especialidad en una sede.
        Schema::create('agendas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('especialista_id')->constrained('especialistas')->restrictOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->foreignId('especialidad_id')->constrained('especialidades')->restrictOnDelete();
            $table->json('dias')->comment('Días ISO: 1 = lunes … 7 = domingo');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->string('consultorio', 30)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sede_id', 'activo']);
        });

        // Novedades: periodos en los que el especialista no atiende.
        Schema::create('ausencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('especialista_id')->constrained('especialistas')->cascadeOnDelete();
            $table->string('tipo', 20)->comment('VACACIONES, INCAPACIDAD, LICENCIA, CAPACITACION, OTRO');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('observacion', 255)->nullable();
            $table->timestamps();

            $table->index(['especialista_id', 'fecha_inicio']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ausencias');
        Schema::dropIfExists('agendas');
    }
};
