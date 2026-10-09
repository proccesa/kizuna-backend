<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Catálogos base de Kizuna. Se pueden recargar de forma independiente con:
 * php artisan db:seed --class=CatalogosSeeder
 */
class CatalogosSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TipoDocumentoSeeder::class,
            DivipolaSeeder::class,
            RegimenSeeder::class,
            ModalidadContratacionSeeder::class,
            CupsSeeder::class,
            EspecialidadSeeder::class,
            PlantillaHcSeeder::class,
        ]);
    }
}
