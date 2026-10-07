<?php

namespace App\Console\Commands;

use App\Modules\Catalogos\Services\ImportadorCups;
use Illuminate\Console\Command;
use Throwable;

class ImportarCups extends Command
{
    protected $signature = 'kizuna:importar-cups
        {archivo? : CSV con las columnas de la tabla CUPSRips de SISPRO (por defecto database/data/cups.csv)}
        {--deshabilitar-ausentes : Marca como no habilitados los códigos que ya no aparecen en el archivo}';

    protected $description = 'Carga o actualiza el catálogo CUPS oficial del MinSalud';

    public function handle(ImportadorCups $importador): int
    {
        $ruta = $this->argument('archivo') ?? database_path('data/cups.csv');

        try {
            $resultado = $importador->importar($ruta, (bool) $this->option('deshabilitar-ausentes'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Catálogo CUPS actualizado: {$resultado['procesados']} códigos procesados.");
        if ($this->option('deshabilitar-ausentes')) {
            $this->line("Códigos que ya no están en el archivo oficial (marcados como no habilitados): {$resultado['deshabilitados']}.");
        }

        return self::SUCCESS;
    }
}
