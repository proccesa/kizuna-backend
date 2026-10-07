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
        Schema::create('sedes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prestador_id')->constrained('prestadores')->restrictOnDelete();
            $table->string('numero_sede', 2)->comment('Número de la sede en el REPS: 01, 02…');
            $table->string('nombre', 150);
            $table->foreignId('municipio_id')->constrained('municipios')->restrictOnDelete();
            $table->string('direccion', 255);
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 150)->nullable();
            $table->unsignedSmallInteger('consultorios')->default(0);
            $table->json('dias_atencion')->comment('Días ISO de atención: 1 = lunes … 7 = domingo');
            $table->time('hora_apertura');
            $table->time('hora_cierre');
            $table->boolean('es_principal')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['prestador_id', 'numero_sede']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sedes');
    }
};
