<?php

namespace Database\Seeders;

use App\Modules\HistoriaClinica\Models\PlantillaHc;
use App\Modules\Servicios\Models\Especialidad;
use Illuminate\Database\Seeder;

/**
 * Plantillas de historia clínica incluidas en Kizuna. Si el esquema cambió, publica una versión nueva
 * (las historias existentes conservan la versión con la que se diligenciaron).
 */
class PlantillaHcSeeder extends Seeder
{
    private const PLANTILLAS = ['preanestesia'];

    public function run(): void
    {
        foreach (self::PLANTILLAS as $archivo) {
            $definicion = require app_path("Modules/HistoriaClinica/Plantillas/{$archivo}.php");

            $plantilla = PlantillaHc::updateOrCreate(['codigo' => $definicion['codigo']], [
                'nombre' => $definicion['nombre'],
                'descripcion' => $definicion['descripcion'],
                'especialidad_id' => Especialidad::where('codigo', $definicion['especialidad_codigo'])->value('id'),
                'activo' => true,
            ]);

            $ultima = $plantilla->versiones()->orderByDesc('version')->first();
            if (! $ultima || $ultima->esquema != $definicion['esquema']) {
                $plantilla->versiones()->create([
                    'version' => ($ultima?->version ?? 0) + 1,
                    'esquema' => $definicion['esquema'],
                    'publicada_en' => now(),
                ]);
            }
        }
    }
}
