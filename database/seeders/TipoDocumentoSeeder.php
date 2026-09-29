<?php

namespace Database\Seeders;

use App\Modules\Users\Models\TipoDocumento;
use Illuminate\Database\Seeder;

class TipoDocumentoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tipos = [
            [
                'codigo' => 'CC',
                'nombre' => 'Cédula de Ciudadanía',
                'descripcion' => 'Documento de identificación oficial para ciudadanos colombianos mayores de edad.',
                'activo' => true,
            ],
            [
                'codigo' => 'CE',
                'nombre' => 'Cédula de Extranjería',
                'descripcion' => 'Documento de identificación para extranjeros residentes en Colombia.',
                'activo' => true,
            ],
            [
                'codigo' => 'TI',
                'nombre' => 'Tarjeta de Identidad',
                'descripcion' => 'Documento de identificación para menores de edad entre 7 y 17 años.',
                'activo' => true,
            ],
            [
                'codigo' => 'PAS',
                'nombre' => 'Pasaporte',
                'descripcion' => 'Documento de viaje internacional.',
                'activo' => true,
            ],
            [
                'codigo' => 'NIT',
                'nombre' => 'Número de Identificación Tributaria',
                'descripcion' => 'Identificación tributaria para personas jurídicas y naturales obligadas.',
                'activo' => true,
            ],
            [
                'codigo' => 'PEP',
                'nombre' => 'Permiso Especial de Permanencia',
                'descripcion' => 'Permiso temporal otorgado a ciudadanos extranjeros.',
                'activo' => true,
            ],
            [
                'codigo' => 'PPT',
                'nombre' => 'Permiso por Protección Temporal',
                'descripcion' => 'Mecanismo de regularización migratoria.',
                'activo' => true,
            ],
        ];

        foreach ($tipos as $tipo) {
            TipoDocumento::updateOrCreate(
                ['codigo' => $tipo['codigo']],
                $tipo
            );
        }
    }
}
