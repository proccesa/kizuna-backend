<?php

namespace App\Modules\Cirugia\Services;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Cirugia\Models\OrdenQuirurgica;
use App\Modules\Cirugia\Requests\GuardarOrdenRequest;
use App\Modules\Common\Services\LectorCsv;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Cargue de órdenes quirúrgicas desde CSV. Cada fila válida sigue el mismo flujo que una orden
 * del endpoint: valida la especialidad y agenda la cita de pre-anestesia.
 */
class ImportadorOrdenes
{
    public const COLUMNAS = [
        'referencia', 'tipo_documento', 'numero_documento', 'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido',
        'fecha_nacimiento', 'sexo', 'telefono', 'correo', 'municipio', 'cups', 'diagnostico_cie10', 'diagnostico', 'prioridad',
        'fecha_orden', 'medico_ordenante', 'contrato',
    ];

    private const COLUMNAS_REQUERIDAS = ['tipo_documento', 'numero_documento', 'primer_nombre', 'primer_apellido', 'fecha_nacimiento', 'sexo', 'cups'];

    private const MAXIMO_FILAS = 5000;

    public const SISTEMA = 'CSV';

    public function __construct(
        private readonly LectorCsv $lector,
        private readonly OrdenServicio $ordenes
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function importar(string $ruta, bool $simular): array
    {
        $filas = $this->lector->leer($ruta, self::COLUMNAS_REQUERIDAS, self::MAXIMO_FILAS);
        $resultado = ['total' => count($filas), 'nuevas' => 0, 'duplicadas' => 0, 'sin_especialidad' => 0, 'citas_asignadas' => 0, 'sin_cupo' => 0, 'errores' => [], 'simulado' => $simular];
        $cupsCache = [];
        $vistas = [];

        foreach ($filas as $numero => $fila) {
            $datos = $this->aDatos($fila);
            $validador = Validator::make($datos, GuardarOrdenRequest::reglas(), GuardarOrdenRequest::mensajes());
            $mensajes = $validador->errors()->all();
            $documento = trim(($fila['tipo_documento'] ?? '').' '.($fila['numero_documento'] ?? ''));

            $clave = $datos['referencia_externa'] ?? null;
            if ($clave !== null && isset($vistas[$clave])) {
                $mensajes[] = "La referencia está repetida en el archivo (fila {$vistas[$clave]}).";
            }
            if ($clave !== null) {
                $vistas[$clave] ??= $numero;
            }

            if ($mensajes) {
                $resultado['errores'][] = ['fila' => $numero, 'documento' => $documento, 'mensajes' => array_values(array_unique($mensajes))];

                continue;
            }

            if ($clave !== null && OrdenQuirurgica::where('sistema_origen', self::SISTEMA)->where('referencia_externa', $clave)->exists()) {
                $resultado['duplicadas']++;

                continue;
            }

            if ($simular) {
                $cups = $cupsCache[$datos['cups']] ??= Cups::where('codigo', $datos['cups'])->first();
                if (! $this->ordenes->especialidadQueAtiende($cups)['especialidad']) {
                    $resultado['sin_especialidad']++;
                }
                $resultado['nuevas']++;

                continue;
            }

            try {
                $orden = $this->ordenes->registrar($datos, 'CSV', self::SISTEMA)['orden'];
                $resultado['nuevas']++;
                match ($orden->estado) {
                    'RECHAZADA' => $resultado['sin_especialidad']++,
                    'CITA_ASIGNADA' => $resultado['citas_asignadas']++,
                    default => $resultado['sin_cupo']++,
                };
            } catch (Throwable $e) {
                $resultado['errores'][] = ['fila' => $numero, 'documento' => $documento, 'mensajes' => ['No se pudo registrar: '.$e->getMessage()]];
            }
        }

        $resultado['con_errores'] = count($resultado['errores']);

        return $resultado;
    }

    /**
     * @return array<string, mixed>
     */
    private function aDatos(array $fila): array
    {
        $opcional = fn (string $c) => LectorCsv::opcional($fila[$c] ?? null);

        return array_filter([
            'referencia_externa' => $opcional('referencia'),
            'paciente' => [
                'tipo_documento' => strtoupper($fila['tipo_documento'] ?? ''),
                'numero_documento' => $fila['numero_documento'] ?? '',
                'primer_nombre' => $fila['primer_nombre'] ?? '',
                'segundo_nombre' => $opcional('segundo_nombre'),
                'primer_apellido' => $fila['primer_apellido'] ?? '',
                'segundo_apellido' => $opcional('segundo_apellido'),
                'fecha_nacimiento' => $this->fecha($fila['fecha_nacimiento'] ?? ''),
                'sexo' => strtoupper(substr(trim($fila['sexo'] ?? ''), 0, 1)),
                'telefono' => $opcional('telefono'),
                'correo' => $opcional('correo'),
                'municipio' => $opcional('municipio'),
            ],
            'cups' => trim($fila['cups'] ?? ''),
            'diagnostico_cie10' => ($c = $opcional('diagnostico_cie10')) ? str_replace('.', '', $c) : null,
            'diagnostico' => $opcional('diagnostico'),
            'prioridad' => ($p = $opcional('prioridad')) ? strtoupper($p) : null,
            'fecha_orden' => ($f = $opcional('fecha_orden')) ? $this->fecha($f) : null,
            'medico_ordenante' => $opcional('medico_ordenante'),
            'contrato_numero' => $opcional('contrato'),
        ], fn ($v) => $v !== null);
    }

    /**
     * Acepta AAAA-MM-DD y DD/MM/AAAA; si no la reconoce, la deja tal cual para que la validación la reporte.
     */
    private function fecha(string $valor): string
    {
        $valor = trim($valor);
        foreach (['Y-m-d', 'd/m/Y', 'j/n/Y', 'd-m-Y'] as $formato) {
            try {
                $fecha = Carbon::createFromFormat("!{$formato}", $valor);
                if ($fecha && $fecha->format($formato) === $valor) {
                    return $fecha->toDateString();
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $valor;
    }
}
