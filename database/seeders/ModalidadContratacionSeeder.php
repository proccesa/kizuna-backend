<?php

namespace Database\Seeders;

use App\Modules\Catalogos\Models\ModalidadContratacion;
use Illuminate\Database\Seeder;

class ModalidadContratacionSeeder extends Seeder
{
    /**
     * Modalidades de contratación entre la IPS y las entidades responsables de pago.
     */
    public function run(): void
    {
        $modalidades = [
            [
                'codigo' => 'PGP',
                'nombre' => 'Pago global prospectivo',
                'descripcion' => 'Valor fijo pactado por un conjunto de servicios y un volumen esperado durante la vigencia del contrato.',
            ],
            [
                'codigo' => 'EVENTO',
                'nombre' => 'Pago por evento',
                'descripcion' => 'Se paga cada servicio efectivamente prestado según las tarifas pactadas.',
            ],
            [
                'codigo' => 'CAPITA',
                'nombre' => 'Pago por capitación',
                'descripcion' => 'Valor fijo por afiliado asignado, independiente del número de servicios prestados.',
            ],
        ];

        foreach ($modalidades as $modalidad) {
            ModalidadContratacion::updateOrCreate(['codigo' => $modalidad['codigo']], $modalidad + ['activo' => true]);
        }
    }
}
