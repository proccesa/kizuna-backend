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
        Schema::create('operadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('Usuario asociado para acceso');
            $table->foreignId('tipo_documento_id')->constrained('tipos_documento')->restrictOnDelete()->comment('Tipo de documento de identidad');
            $table->string('documento', 30)->unique()->comment('Número de documento de identificación');
            $table->string('nombre', 100)->comment('Primer y segundo nombre');
            $table->string('apellido', 100)->comment('Primer y segundo apellido');
            $table->string('telefono', 30)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tipo_documento_id', 'documento']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operadores');
    }
};
