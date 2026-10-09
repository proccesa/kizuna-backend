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
        Schema::create('contratos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entidad_id')->constrained('entidades')->restrictOnDelete();
            $table->string('numero', 50);
            $table->foreignId('modalidad_contratacion_id')->constrained('modalidades_contratacion')->restrictOnDelete();
            $table->foreignId('regimen_id')->constrained('regimenes')->restrictOnDelete();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->decimal('valor', 18, 2)->default(0);
            $table->string('objeto', 500)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entidad_id', 'numero']);
            $table->index(['fecha_inicio', 'fecha_fin']);
        });

        // Sedes donde se ejecuta el contrato.
        Schema::create('contrato_sede', function (Blueprint $table) {
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();

            $table->primary(['contrato_id', 'sede_id']);
        });

        // Servicios CUPS pactados, con su volumen (PGP) o tarifa (evento) si aplica.
        Schema::create('contrato_cups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $table->foreignId('cups_id')->constrained('cups')->restrictOnDelete();
            $table->unsignedInteger('cantidad')->nullable()->comment('Volumen pactado en la vigencia');
            $table->decimal('tarifa', 14, 2)->nullable()->comment('Valor unitario pactado');
            $table->timestamps();

            $table->unique(['contrato_id', 'cups_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contrato_cups');
        Schema::dropIfExists('contrato_sede');
        Schema::dropIfExists('contratos');
    }
};
