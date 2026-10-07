<?php

namespace Database\Factories;

use App\Modules\Catalogos\Models\Cups;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cups>
 */
class CupsFactory extends Factory
{
    protected $model = Cups::class;

    public function definition(): array
    {
        $nombre = 'CONSULTA DE PRUEBA '.Str::upper(fake()->unique()->lexify('????????'));

        return [
            'codigo' => (string) fake()->unique()->numberBetween(100000, 999999),
            'nombre' => $nombre,
            'nombre_normalizado' => Str::upper(Str::ascii($nombre)),
            'seccion' => 'ANEXO TECNICO 2 - SECCION 08 CONSULTA, MONITORIZACION Y PROCEDIMIENTOS DIAGNOSTICOS',
            'habilitado' => true,
            'es_quirurgico' => false,
        ];
    }
}
