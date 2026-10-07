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
        Schema::create('prestadores', function (Blueprint $table) {
            $table->id();
            $table->string('nit', 15)->comment('NIT sin dígito de verificación');
            $table->char('digito_verificacion', 1)->comment('Dígito de verificación del NIT (DIAN)');
            $table->string('razon_social', 200);
            $table->string('nombre_comercial', 200)->nullable();
            $table->string('codigo_habilitacion', 12)->nullable()->comment('Código del prestador en el REPS');
            $table->string('naturaleza', 10)->comment('PRIVADA, PUBLICA o MIXTA');
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 150)->nullable();
            $table->string('representante_legal', 150)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('nit');
            $table->index('codigo_habilitacion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prestadores');
    }
};
