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
        Schema::create('municipios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departamento_id')->constrained('departamentos')->restrictOnDelete();
            $table->char('codigo', 5)->unique()->comment('Código DANE del municipio (DIVIPOLA)');
            $table->string('nombre', 120);
            $table->string('nombre_normalizado', 120)->index()->comment('Nombre en mayúsculas y sin tildes, para búsquedas');
            $table->string('tipo', 40)->comment('Municipio, Isla o Área no municipalizada');
            $table->decimal('latitud', 10, 6)->nullable();
            $table->decimal('longitud', 10, 6)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('municipios');
    }
};
