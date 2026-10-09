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
        // Ajustes de la IPS en clave/valor (p. ej. reglas de pre-anestesia).
        Schema::create('ajustes', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 100)->unique();
            $table->json('valor');
            $table->timestamps();
        });

        // Sistemas externos que se conectan a Kizuna con un token propio.
        Schema::create('clientes_integracion', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->string('descripcion', 255)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamp('ultimo_uso_en')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes_integracion');
        Schema::dropIfExists('ajustes');
    }
};
