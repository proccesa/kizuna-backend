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
        Schema::create('ordenes_quirurgicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paciente_id')->constrained('pacientes')->restrictOnDelete();
            $table->foreignId('contrato_id')->nullable()->constrained('contratos')->nullOnDelete();
            $table->foreignId('cups_id')->constrained('cups')->restrictOnDelete();
            $table->foreignId('especialidad_id')->nullable()->constrained('especialidades')->nullOnDelete()->comment('Especialidad de la IPS que atiende la cirugía');
            $table->string('diagnostico_cie10', 10)->nullable();
            $table->string('diagnostico', 255)->nullable();
            $table->string('prioridad', 15)->default('ELECTIVA')->comment('ELECTIVA o PRIORITARIA');
            $table->date('fecha_orden');
            $table->string('medico_ordenante', 150)->nullable();
            $table->string('observaciones', 500)->nullable();
            $table->string('origen', 10)->comment('API, CSV o MANUAL');
            $table->string('sistema_origen', 100)->nullable();
            $table->string('referencia_externa', 100)->nullable();
            $table->string('estado', 20)->comment('RECHAZADA, PENDIENTE_CITA, CITA_ASIGNADA, APTA, NO_APTA, APLAZADA, CANCELADA');
            $table->string('motivo_estado', 255)->nullable();
            // Resultado de la valoración pre-anestésica
            $table->string('concepto', 30)->nullable();
            $table->unsignedTinyInteger('asa')->nullable();
            $table->date('aval_desde')->nullable();
            $table->date('aval_hasta')->nullable();
            $table->date('programable_desde')->nullable()->comment('Primera fecha posible de cirugía (tras suspender medicamentos)');
            $table->unsignedBigInteger('historia_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['sistema_origen', 'referencia_externa']);
            $table->index(['estado', 'fecha_orden']);
        });

        Schema::create('citas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paciente_id')->constrained('pacientes')->restrictOnDelete();
            $table->foreignId('especialista_id')->constrained('especialistas')->restrictOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->foreignId('especialidad_id')->constrained('especialidades')->restrictOnDelete();
            $table->foreignId('cups_id')->constrained('cups')->restrictOnDelete();
            $table->foreignId('agenda_id')->nullable()->constrained('agendas')->nullOnDelete();
            $table->foreignId('orden_id')->nullable()->constrained('ordenes_quirurgicas')->nullOnDelete();
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->string('consultorio', 30)->nullable();
            $table->string('tipo', 20)->default('PREANESTESIA');
            $table->string('estado', 15)->default('PROGRAMADA')->comment('PROGRAMADA, ATENDIDA, CANCELADA, NO_ASISTIO');
            $table->string('origen', 12)->default('AUTOMATICA')->comment('AUTOMATICA o MANUAL');
            $table->string('motivo_cancelacion', 255)->nullable();
            $table->timestamps();

            $table->index(['especialista_id', 'fecha']);
            $table->index(['fecha', 'estado']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('citas');
        Schema::dropIfExists('ordenes_quirurgicas');
    }
};
