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
        // Cirujano indicado en la orden (si el sistema externo lo envía).
        Schema::table('ordenes_quirurgicas', function (Blueprint $table) {
            $table->foreignId('cirujano_id')->nullable()->after('especialidad_id')->constrained('especialistas')->nullOnDelete();
        });

        // Vínculo entre la cuenta de usuario y el profesional (para firmar historias y ver "mis citas").
        Schema::table('especialistas', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->unique()->constrained('users')->nullOnDelete();
        });

        // Cada ejecución del motor: genera una propuesta que el jefe de cirugía aprueba o descarta.
        Schema::create('programacion_corridas', function (Blueprint $table) {
            $table->id();
            $table->date('desde');
            $table->date('hasta');
            $table->json('sede_ids')->nullable();
            $table->string('estado', 12)->default('PROPUESTA')->comment('PROPUESTA, APROBADA o DESCARTADA');
            $table->json('resumen')->nullable()->comment('Evaluadas, programadas y por qué no se programaron las demás');
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('aprobada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cerrada_en')->nullable();
            $table->timestamps();
        });

        // Cirugías programadas (propuestas por el motor o aprobadas).
        Schema::create('cirugias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corrida_id')->nullable()->constrained('programacion_corridas')->nullOnDelete();
            $table->foreignId('orden_id')->constrained('ordenes_quirurgicas')->restrictOnDelete();
            $table->foreignId('paciente_id')->constrained('pacientes')->restrictOnDelete();
            $table->foreignId('cups_id')->constrained('cups')->restrictOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->foreignId('sala_id')->nullable()->constrained('salas')->restrictOnDelete();
            $table->foreignId('cirujano_id')->constrained('especialistas')->restrictOnDelete();
            $table->foreignId('anestesiologo_id')->nullable()->constrained('especialistas')->restrictOnDelete();
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->string('estado', 12)->default('PROPUESTA')->comment('PROPUESTA, APROBADA, REALIZADA o CANCELADA');
            $table->decimal('puntaje', 10, 2)->default(0);
            $table->json('prioridad')->nullable()->comment('Factores del puntaje, para explicar el orden');
            $table->json('avisos')->nullable();
            $table->string('motivo_cancelacion', 255)->nullable();
            $table->timestamps();

            $table->index(['fecha', 'estado']);
            $table->index(['cirujano_id', 'fecha']);
        });

        // Anestesiólogo asignado a una sala en una jornada (cubre todas las cirugías de ese bloque).
        Schema::create('asignaciones_anestesia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sala_id')->constrained('salas')->cascadeOnDelete();
            $table->foreignId('especialista_id')->constrained('especialistas')->cascadeOnDelete();
            $table->foreignId('corrida_id')->nullable()->constrained('programacion_corridas')->nullOnDelete();
            $table->date('fecha');
            $table->string('jornada', 6)->comment('MANANA o TARDE');
            $table->timestamps();

            $table->unique(['sala_id', 'fecha', 'jornada']);
            $table->unique(['especialista_id', 'fecha', 'jornada']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asignaciones_anestesia');
        Schema::dropIfExists('cirugias');
        Schema::dropIfExists('programacion_corridas');
        Schema::table('especialistas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('ordenes_quirurgicas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cirujano_id');
        });
    }
};
