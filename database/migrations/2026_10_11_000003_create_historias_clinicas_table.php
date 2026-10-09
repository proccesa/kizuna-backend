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
        // Plantillas de historia clínica por especialidad (pre-anestesia, control de HTA…).
        Schema::create('plantillas_hc', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 60)->unique();
            $table->string('nombre', 150);
            $table->string('descripcion', 500)->nullable();
            $table->foreignId('especialidad_id')->nullable()->constrained('especialidades')->nullOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Cada cambio de la plantilla es una versión nueva; las historias guardan la versión con la que se diligenciaron.
        Schema::create('plantilla_hc_versiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plantilla_id')->constrained('plantillas_hc')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('esquema');
            $table->timestamp('publicada_en')->nullable();
            $table->timestamps();

            $table->unique(['plantilla_id', 'version']);
        });

        Schema::create('historias_clinicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paciente_id')->constrained('pacientes')->restrictOnDelete();
            $table->foreignId('plantilla_version_id')->constrained('plantilla_hc_versiones')->restrictOnDelete();
            $table->foreignId('especialista_id')->nullable()->constrained('especialistas')->nullOnDelete();
            $table->foreignId('cita_id')->nullable()->constrained('citas')->nullOnDelete();
            $table->foreignId('orden_id')->nullable()->constrained('ordenes_quirurgicas')->nullOnDelete();
            $table->string('origen', 10)->default('KIZUNA')->comment('KIZUNA o EXTERNO');
            $table->string('sistema_origen', 100)->nullable();
            $table->string('referencia_externa', 100)->nullable();
            $table->string('profesional_externo', 200)->nullable();
            $table->string('estado', 12)->default('BORRADOR')->comment('BORRADOR, FINALIZADA o ANULADA');
            $table->json('respuestas')->nullable();
            $table->json('resultado')->nullable()->comment('Escalas calculadas, alertas y concepto');
            $table->timestamp('finalizada_en')->nullable();
            $table->foreignId('finalizada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_anulacion', 500)->nullable();
            $table->timestamp('anulada_en')->nullable();
            $table->timestamps();

            $table->index(['paciente_id', 'estado']);
        });

        // Auditoría: quién creó, editó, finalizó, anuló o agregó notas a cada historia.
        Schema::create('historia_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('historia_id')->constrained('historias_clinicas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion', 30);
            $table->text('detalle')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('ordenes_quirurgicas', function (Blueprint $table) {
            $table->foreign('historia_id')->references('id')->on('historias_clinicas')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ordenes_quirurgicas', fn (Blueprint $table) => $table->dropForeign(['historia_id']));
        Schema::dropIfExists('historia_eventos');
        Schema::dropIfExists('historias_clinicas');
        Schema::dropIfExists('plantilla_hc_versiones');
        Schema::dropIfExists('plantillas_hc');
    }
};
