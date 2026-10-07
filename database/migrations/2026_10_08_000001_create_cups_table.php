<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo oficial CUPS (Clasificación Única de Procedimientos en Salud) del MinSalud,
     * tomado de la tabla de referencia CUPSRips de SISPRO. Es de solo lectura en Kizuna.
     */
    public function up(): void
    {
        Schema::create('cups', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 10)->unique();
            $table->string('nombre', 500);
            $table->string('nombre_normalizado', 500)->comment('Mayúsculas sin tildes, para búsquedas');
            $table->string('seccion', 255)->nullable()->comment('Anexo técnico y sección de la resolución CUPS');
            $table->boolean('habilitado')->default(true)->comment('Habilitado para reporte en RIPS');
            $table->string('uso_codigo', 10)->nullable()->comment('Uso del código CUP (Extra I de SISPRO)');
            $table->boolean('es_quirurgico')->default(false);
            $table->string('sexo', 1)->nullable()->comment('Restricción de sexo: H, M, Z (ambos)');
            $table->string('ambito', 1)->nullable()->comment('Ámbito de realización');
            $table->string('cobertura', 5)->nullable();
            $table->timestamp('actualizado_minsalud')->nullable()->comment('Fecha de actualización en SISPRO');
            $table->timestamps();

            $table->index('nombre_normalizado');
            $table->index('habilitado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cups');
    }
};
