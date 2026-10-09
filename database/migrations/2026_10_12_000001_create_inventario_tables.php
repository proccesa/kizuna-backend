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
        // Quirófanos y salas de procedimientos de cada sede.
        Schema::create('salas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->string('codigo', 20);
            $table->string('nombre', 100);
            $table->string('tipo', 25)->comment('QUIROFANO, SALA_PROCEDIMIENTOS, SALA_PARTOS u OTRA');
            $table->string('observaciones', 500)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sede_id', 'tipo']);
        });

        // En qué tipo de sala se realiza cada CUPS en cada sede (null = no requiere sala).
        Schema::table('portafolio_servicios', function (Blueprint $table) {
            $table->string('tipo_sala', 25)->nullable()->after('duracion_minutos');
        });

        // Catálogo: tipos de equipo biomédico, cajas de instrumental e insumos.
        Schema::create('inventario_items', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 15)->comment('EQUIPO, INSTRUMENTAL o INSUMO');
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 200);
            $table->string('descripcion', 500)->nullable();
            $table->string('unidad_medida', 30)->nullable()->comment('Insumos: unidad, caja, frasco…');
            $table->string('clasificacion_riesgo', 5)->nullable()->comment('Equipos: I, IIA, IIB o III');
            $table->boolean('requiere_calibracion')->default(false);
            $table->unsignedSmallInteger('periodicidad_mantenimiento_meses')->nullable();
            $table->unsignedSmallInteger('periodicidad_calibracion_meses')->nullable();
            $table->unsignedSmallInteger('minutos_esterilizacion')->nullable()->comment('Instrumental: tiempo de reproceso entre usos');
            $table->unsignedInteger('stock_minimo')->nullable()->comment('Insumos: alerta por sede');
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tipo', 'activo']);
        });

        // Unidades identificables: cada equipo biomédico (placa) y cada caja de instrumental.
        Schema::create('inventario_unidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventario_items')->restrictOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->foreignId('sala_id')->nullable()->constrained('salas')->nullOnDelete()->comment('Equipo fijo instalado en una sala; null = móvil');
            $table->string('codigo', 60)->unique()->comment('Placa de inventario o código de la caja');
            $table->string('serie', 80)->nullable();
            $table->string('marca', 80)->nullable();
            $table->string('modelo', 80)->nullable();
            $table->string('registro_invima', 60)->nullable();
            $table->string('estado', 20)->default('OPERATIVO')->comment('OPERATIVO, MANTENIMIENTO, FUERA_SERVICIO o BAJA');
            $table->date('ultimo_mantenimiento')->nullable();
            $table->date('proximo_mantenimiento')->nullable();
            $table->date('calibracion_vence')->nullable();
            $table->string('observaciones', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['item_id', 'sede_id', 'estado']);
        });

        Schema::create('inventario_mantenimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidad_id')->constrained('inventario_unidades')->cascadeOnDelete();
            $table->string('tipo', 15)->comment('PREVENTIVO, CORRECTIVO o CALIBRACION');
            $table->dateTime('inicio');
            $table->dateTime('fin');
            $table->string('estado', 12)->default('PROGRAMADO')->comment('PROGRAMADO, TERMINADO o CANCELADO');
            $table->string('responsable', 150)->nullable();
            $table->string('observaciones', 500)->nullable();
            $table->timestamps();

            $table->index(['unidad_id', 'inicio']);
        });

        // Insumos: cantidades por sede y lote.
        Schema::create('inventario_existencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventario_items')->restrictOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->string('lote', 60)->default('');
            $table->date('vence')->nullable();
            $table->integer('cantidad')->default(0);
            $table->timestamps();

            $table->unique(['item_id', 'sede_id', 'lote']);
        });

        Schema::create('inventario_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventario_items')->restrictOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->foreignId('existencia_id')->nullable()->constrained('inventario_existencias')->nullOnDelete();
            $table->string('tipo', 15)->comment('ENTRADA, SALIDA, AJUSTE, CONSUMO o SINCRONIZACION');
            $table->integer('cantidad')->comment('Con signo');
            $table->integer('saldo')->comment('Saldo del lote después del movimiento');
            $table->string('motivo', 255)->nullable();
            $table->string('origen', 10)->default('MANUAL');
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['item_id', 'sede_id']);
        });

        // Reservas de recursos para un procedimiento programado (las crea el motor de programación).
        Schema::create('inventario_reservas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->nullable()->constrained('inventario_items')->restrictOnDelete();
            $table->foreignId('unidad_id')->nullable()->constrained('inventario_unidades')->restrictOnDelete();
            $table->foreignId('sala_id')->nullable()->constrained('salas')->restrictOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->integer('cantidad')->default(1);
            $table->dateTime('inicio');
            $table->dateTime('fin');
            $table->dateTime('libera_en')->comment('Fin + tiempo de esterilización (instrumental)');
            $table->string('estado', 12)->default('ACTIVA')->comment('ACTIVA, CONSUMIDA o LIBERADA');
            $table->string('referencia_tipo', 30)->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->timestamps();

            $table->index(['sede_id', 'estado', 'inicio']);
        });

        // Lo que necesita cada CUPS. sede_id null = lista base; con sede = ajuste para esa sede (cantidad 0 = no se usa allí).
        Schema::create('requerimientos_cups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cups_id')->constrained('cups')->cascadeOnDelete();
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventario_items')->restrictOnDelete();
            $table->unsignedInteger('cantidad');
            $table->string('notas', 255)->nullable();
            $table->timestamps();

            $table->index(['cups_id', 'sede_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requerimientos_cups');
        Schema::dropIfExists('inventario_reservas');
        Schema::dropIfExists('inventario_movimientos');
        Schema::dropIfExists('inventario_existencias');
        Schema::dropIfExists('inventario_mantenimientos');
        Schema::dropIfExists('inventario_unidades');
        Schema::dropIfExists('inventario_items');
        Schema::table('portafolio_servicios', fn (Blueprint $table) => $table->dropColumn('tipo_sala'));
        Schema::dropIfExists('salas');
    }
};
