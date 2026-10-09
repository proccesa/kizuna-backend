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
        // EPS y demás pagadores con los que la IPS tiene contratos.
        Schema::create('entidades', function (Blueprint $table) {
            $table->id();
            $table->string('nit', 15);
            $table->char('digito_verificacion', 1);
            $table->string('razon_social', 200);
            $table->string('sigla', 30)->nullable();
            $table->string('codigo_minsalud', 10)->nullable()->comment('Código de la EAPB ante MinSalud, p. ej. EPS037');
            $table->string('tipo', 30)->comment('EPS, MEDICINA_PREPAGADA, ARL, ASEGURADORA, ENTIDAD_TERRITORIAL, REGIMEN_ESPECIAL, OTRO');
            $table->string('telefono', 30)->nullable();
            $table->string('correo', 150)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('nit');
        });

        Schema::create('entidad_regimen', function (Blueprint $table) {
            $table->foreignId('entidad_id')->constrained('entidades')->cascadeOnDelete();
            $table->foreignId('regimen_id')->constrained('regimenes')->cascadeOnDelete();

            $table->primary(['entidad_id', 'regimen_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entidad_regimen');
        Schema::dropIfExists('entidades');
    }
};
