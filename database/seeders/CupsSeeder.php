<?php

namespace Database\Seeders;

use App\Modules\Catalogos\Services\ImportadorCups;
use Illuminate\Database\Seeder;

/**
 * Carga el catálogo CUPS oficial (MinSalud, tabla CUPSRips de SISPRO) desde
 * database/data/cups.csv. Para actualizarlo: php artisan kizuna:importar-cups.
 */
class CupsSeeder extends Seeder
{
    public function run(ImportadorCups $importador): void
    {
        // Las pruebas crean sus CUPS con la factory; cargar 13.000+ códigos en cada prueba
        // la haría lenta. La importación real se prueba en CatalogoCupsTest.
        if (app()->runningUnitTests()) {
            return;
        }

        $ruta = database_path('data/cups.csv');

        if (! file_exists($ruta)) {
            $this->command?->warn('No existe database/data/cups.csv: se omite la carga del catálogo CUPS.');

            return;
        }

        $resultado = $importador->importar($ruta);
        $this->command?->info("Catálogo CUPS: {$resultado['procesados']} códigos.");
    }
}
