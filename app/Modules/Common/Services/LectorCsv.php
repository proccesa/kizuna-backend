<?php

namespace App\Modules\Common\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lee los CSV de los cargues masivos (especialistas, poblaciones…).
 *
 * - Acepta coma o punto y coma como separador (Excel en español usa punto y coma).
 * - Acepta UTF-8 (con o sin BOM) y Windows-1252, que es como guarda Excel en Windows.
 * - Normaliza los encabezados: "Número Documento" → "numero_documento".
 * - Devuelve las filas indexadas por su número en el archivo (la fila 1 es el encabezado).
 */
class LectorCsv
{
    /**
     * @param  list<string>  $requeridas  Columnas que deben venir en el encabezado.
     * @return array<int, array<string, string>>
     *
     * @throws ValidationException si el archivo no tiene la estructura esperada.
     */
    public function leer(string $ruta, array $requeridas, int $maximoFilas): array
    {
        $contenido = (string) file_get_contents($ruta);
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $lineas = preg_split('/\r\n|\n|\r/', trim($contenido));
        $primera = $lineas[0] ?? '';
        $separador = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';

        $encabezados = array_map(
            fn ($h) => Str::snake(preg_replace('/[^A-Za-z0-9 _]/', '', Str::ascii(trim((string) $h)))),
            str_getcsv($primera, $separador, '"', '')
        );
        $encabezados = array_map(fn ($h) => preg_replace('/_+/', '_', $h), $encabezados);

        $faltantes = array_diff($requeridas, $encabezados);
        if ($faltantes) {
            throw ValidationException::withMessages([
                'archivo' => ['Faltan las columnas: '.implode(', ', $faltantes).'. Descarga la plantilla para ver el formato.'],
            ]);
        }

        $filas = [];
        foreach (array_slice($lineas, 1) as $i => $linea) {
            if (trim(str_replace([$separador, '"'], '', $linea)) === '') {
                continue;
            }
            $valores = array_map('trim', str_getcsv($linea, $separador, '"', ''));
            $filas[$i + 2] = array_combine($encabezados, array_pad(array_slice($valores, 0, count($encabezados)), count($encabezados), ''));

            if (count($filas) > $maximoFilas) {
                throw ValidationException::withMessages([
                    'archivo' => ['El archivo supera las '.number_format($maximoFilas, 0, ',', '.').' filas. Divídelo en varios.'],
                ]);
            }
        }

        if (! $filas) {
            throw ValidationException::withMessages(['archivo' => ['El archivo no tiene filas con datos.']]);
        }

        return $filas;
    }

    /**
     * "MARÍA JOSÉ" → "María José". Respeta lo que ya viene en mayúsculas y minúsculas.
     */
    public static function nombrePropio(?string $valor): string
    {
        $valor = preg_replace('/\s+/', ' ', trim((string) $valor));

        return mb_strtoupper($valor) === $valor ? mb_convert_case(mb_strtolower($valor), MB_CASE_TITLE) : $valor;
    }

    public static function opcional(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
