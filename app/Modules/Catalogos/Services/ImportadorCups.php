<?php

namespace App\Modules\Catalogos\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Carga el catálogo CUPS desde un CSV con las columnas de la tabla de referencia
 * CUPSRips de SISPRO (MinSalud): Codigo, Nombre, Descripcion, Habilitado,
 * Extra_I:UsoCodigoCUP, Extra_II:Qx, Extra_VI:Sexo, Extra_VII:Ambito,
 * Extra_IX:Cobertura y Fecha_Actualizacion.
 *
 * Es idempotente (upsert por código). Opcionalmente marca como no habilitados
 * los códigos que ya no vienen en el archivo oficial.
 */
class ImportadorCups
{
    private const COLUMNAS_REQUERIDAS = ['Codigo', 'Nombre'];

    /**
     * @return array{procesados: int, deshabilitados: int}
     */
    public function importar(string $ruta, bool $deshabilitarAusentes = false): array
    {
        if (! is_readable($ruta)) {
            throw new RuntimeException("No se puede leer el archivo CUPS: {$ruta}");
        }

        $archivo = fopen($ruta, 'r');
        $encabezados = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), fgetcsv($archivo, escape: '') ?: []);

        foreach (self::COLUMNAS_REQUERIDAS as $columna) {
            if (! in_array($columna, $encabezados, true)) {
                fclose($archivo);
                throw new RuntimeException("El archivo CUPS no tiene la columna \"{$columna}\". Columnas encontradas: ".implode(', ', $encabezados));
            }
        }

        $ahora = now();
        $codigos = [];
        $lote = [];
        $procesados = 0;

        $guardar = function (array $filas) {
            DB::table('cups')->upsert(
                $filas,
                ['codigo'],
                ['nombre', 'nombre_normalizado', 'seccion', 'habilitado', 'uso_codigo', 'es_quirurgico', 'sexo', 'ambito', 'cobertura', 'actualizado_minsalud', 'updated_at']
            );
        };

        DB::transaction(function () use ($archivo, $encabezados, $ahora, &$codigos, &$lote, &$procesados, $guardar) {
            while (($valores = fgetcsv($archivo, escape: '')) !== false) {
                if (count($valores) < count($encabezados)) {
                    continue;
                }
                $fila = array_combine($encabezados, array_slice($valores, 0, count($encabezados)));
                $codigo = trim((string) $fila['Codigo']);
                $nombre = trim((string) $fila['Nombre']);
                if ($codigo === '' || $nombre === '') {
                    continue;
                }

                $codigos[] = $codigo;
                $lote[] = [
                    'codigo' => $codigo,
                    'nombre' => Str::limit($nombre, 500, ''),
                    'nombre_normalizado' => Str::limit(Str::upper(Str::ascii($nombre)), 500, ''),
                    'seccion' => $this->texto($fila['Descripcion'] ?? null, 255),
                    'habilitado' => strtoupper(trim((string) ($fila['Habilitado'] ?? 'SI'))) === 'SI',
                    'uso_codigo' => $this->texto($fila['Extra_I:UsoCodigoCUP'] ?? null, 10),
                    'es_quirurgico' => strtoupper(trim((string) ($fila['Extra_II:Qx'] ?? ''))) === 'S',
                    'sexo' => $this->texto($fila['Extra_VI:Sexo'] ?? null, 1),
                    'ambito' => $this->texto($fila['Extra_VII:Ambito'] ?? null, 1),
                    'cobertura' => $this->texto($fila['Extra_IX:Cobertura'] ?? null, 5),
                    'actualizado_minsalud' => $this->fecha($fila['Fecha_Actualizacion'] ?? null),
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
                $procesados++;

                if (count($lote) === 500) {
                    $guardar($lote);
                    $lote = [];
                }
            }

            if ($lote) {
                $guardar($lote);
            }
        });

        fclose($archivo);

        $deshabilitados = 0;
        if ($deshabilitarAusentes && $codigos) {
            $deshabilitados = DB::table('cups')
                ->where('habilitado', true)
                ->whereNotIn('codigo', $codigos)
                ->update(['habilitado' => false, 'updated_at' => $ahora]);
        }

        return ['procesados' => $procesados, 'deshabilitados' => $deshabilitados];
    }

    private function texto(mixed $valor, int $max): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : Str::limit($texto, $max, '');
    }

    /**
     * SISPRO publica la fecha como "2026-10-02 01:34:06 PM" en el Excel exportado
     * y como "10/2/2026 1:34:06 PM" (mes/día/año) en la consulta web.
     */
    private function fecha(mixed $valor): ?Carbon
    {
        $texto = trim((string) $valor);
        if ($texto === '') {
            return null;
        }

        foreach (['Y-m-d h:i:s A', 'n/j/Y g:i:s A', 'Y-m-d H:i:s', 'Y-m-d'] as $formato) {
            try {
                return Carbon::createFromFormat($formato, $texto);
            } catch (Throwable) {
                // Probar el siguiente formato.
            }
        }

        return null;
    }
}
