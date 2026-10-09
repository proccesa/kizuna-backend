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
        // Un paciente es una persona: se registra una sola vez aunque esté en varias poblaciones.
        Schema::create('pacientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_documento_id')->constrained('tipos_documento')->restrictOnDelete();
            $table->string('numero_documento', 20);
            $table->string('primer_nombre', 60);
            $table->string('segundo_nombre', 60)->nullable();
            $table->string('primer_apellido', 60);
            $table->string('segundo_apellido', 60)->nullable();
            $table->date('fecha_nacimiento');
            $table->char('sexo', 1)->comment('F, M o I (indeterminado)');
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 150)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->foreignId('municipio_id')->nullable()->constrained('municipios')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tipo_documento_id', 'numero_documento']);
        });

        // Grupo de pacientes asignado a un contrato (p. ej. "Afiliados contributivo Cali").
        Schema::create('poblaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contrato_id')->constrained('contratos')->restrictOnDelete();
            $table->string('nombre', 150);
            $table->string('descripcion', 500)->nullable();
            $table->timestamp('ultimo_cargue_en')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('poblacion_paciente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poblacion_id')->constrained('poblaciones')->cascadeOnDelete();
            $table->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();
            $table->json('cohortes')->nullable()->comment('Programas o grupos de riesgo: Hipertensión, Diabetes…');
            $table->boolean('activo')->default(true)->comment('Falso si no vino en el último cargue con reemplazo');
            $table->timestamps();

            $table->unique(['poblacion_id', 'paciente_id']);
            $table->index(['poblacion_id', 'activo']);
        });

        // Historial de cargues de cada población.
        Schema::create('poblacion_cargues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poblacion_id')->constrained('poblaciones')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archivo', 255);
            $table->string('modo', 12)->comment('REEMPLAZAR o AGREGAR');
            $table->unsignedInteger('total');
            $table->unsignedInteger('nuevos');
            $table->unsignedInteger('actualizados');
            $table->unsignedInteger('retirados');
            $table->unsignedInteger('con_errores');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poblacion_cargues');
        Schema::dropIfExists('poblacion_paciente');
        Schema::dropIfExists('poblaciones');
        Schema::dropIfExists('pacientes');
    }
};
