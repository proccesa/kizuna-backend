<?php

namespace App\Console\Commands;

use App\Modules\Cirugia\Services\OrdenServicio;
use Illuminate\Console\Command;

class AsignarCitasPendientes extends Command
{
    protected $signature = 'kizuna:asignar-citas-pendientes';

    protected $description = 'Busca cupo de pre-anestesia para las órdenes que quedaron sin cita (prioritarias y antiguas primero).';

    public function handle(OrdenServicio $ordenes): int
    {
        $resultado = $ordenes->asignarPendientes();
        $this->info("Órdenes revisadas: {$resultado['revisadas']} · citas asignadas: {$resultado['asignadas']}");

        return self::SUCCESS;
    }
}
