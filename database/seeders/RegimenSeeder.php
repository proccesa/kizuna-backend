<?php

namespace Database\Seeders;

use App\Modules\Catalogos\Models\Regimen;
use Illuminate\Database\Seeder;

class RegimenSeeder extends Seeder
{
    /**
     * Regímenes de afiliación al Sistema General de Seguridad Social en Salud.
     */
    public function run(): void
    {
        $regimenes = [
            [
                'codigo' => 'CONTRIBUTIVO',
                'nombre' => 'Contributivo',
                'descripcion' => 'Afiliados con capacidad de pago: trabajadores dependientes, independientes y pensionados.',
            ],
            [
                'codigo' => 'SUBSIDIADO',
                'nombre' => 'Subsidiado',
                'descripcion' => 'Población sin capacidad de pago, cuya afiliación es financiada por el Estado.',
            ],
            [
                'codigo' => 'ESPECIAL',
                'nombre' => 'Especial y de excepción',
                'descripcion' => 'Fuerzas Militares, Policía Nacional, Magisterio, Ecopetrol y universidades públicas, entre otros.',
            ],
        ];

        foreach ($regimenes as $regimen) {
            Regimen::updateOrCreate(['codigo' => $regimen['codigo']], $regimen + ['activo' => true]);
        }
    }
}
