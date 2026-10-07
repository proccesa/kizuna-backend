<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carga departamentos y municipios de Colombia (DIVIPOLA del DANE).
 *
 * Fuente: datos abiertos del DANE en datos.gov.co, conjunto gdxc-w37w
 * ("DIVIPOLA - Códigos municipios"), guardado en database/data/divipola.csv.
 * Es idempotente: se puede ejecutar varias veces sin duplicar registros.
 */
class DivipolaSeeder extends Seeder
{
    public function run(): void
    {
        $ruta = database_path('data/divipola.csv');
        $archivo = fopen($ruta, 'r');

        if ($archivo === false) {
            throw new RuntimeException("No se pudo abrir el archivo DIVIPOLA: {$ruta}");
        }

        $encabezados = fgetcsv($archivo, escape: '');
        $filas = [];
        while (($fila = fgetcsv($archivo, escape: '')) !== false) {
            $filas[] = array_combine($encabezados, $fila);
        }
        fclose($archivo);

        $ahora = now();

        DB::transaction(function () use ($filas, $ahora) {
            // Departamentos
            $departamentos = collect($filas)
                ->unique('codigo_departamento')
                ->map(fn ($f) => [
                    'codigo' => $f['codigo_departamento'],
                    'nombre' => $f['departamento'],
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ])
                ->values()
                ->all();

            DB::table('departamentos')->upsert($departamentos, ['codigo'], ['nombre', 'updated_at']);

            $idsDepartamento = DB::table('departamentos')->pluck('id', 'codigo');

            // Municipios
            $municipios = array_map(fn ($f) => [
                'departamento_id' => $idsDepartamento[$f['codigo_departamento']],
                'codigo' => $f['codigo_municipio'],
                'nombre' => $f['municipio'],
                'nombre_normalizado' => $f['municipio_normalizado'],
                'tipo' => $f['tipo'],
                'latitud' => $f['latitud'] !== '' ? $f['latitud'] : null,
                'longitud' => $f['longitud'] !== '' ? $f['longitud'] : null,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ], $filas);

            foreach (array_chunk($municipios, 300) as $lote) {
                DB::table('municipios')->upsert(
                    $lote,
                    ['codigo'],
                    ['departamento_id', 'nombre', 'nombre_normalizado', 'tipo', 'latitud', 'longitud', 'updated_at']
                );
            }
        });
    }
}
