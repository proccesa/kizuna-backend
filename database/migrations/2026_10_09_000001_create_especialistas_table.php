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
        Schema::create('especialistas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_documento_id')->constrained('tipos_documento')->restrictOnDelete();
            $table->string('numero_documento', 20);
            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->string('registro_profesional', 30)->nullable()->comment('Registro en el ReTHUS o tarjeta profesional');
            $table->string('correo', 150)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tipo_documento_id', 'numero_documento']);
        });

        Schema::create('especialidad_especialista', function (Blueprint $table) {
            $table->foreignId('especialista_id')->constrained('especialistas')->cascadeOnDelete();
            $table->foreignId('especialidad_id')->constrained('especialidades')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['especialista_id', 'especialidad_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('especialidad_especialista');
        Schema::dropIfExists('especialistas');
    }
};
