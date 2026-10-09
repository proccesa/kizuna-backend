<?php

namespace App\Modules\Talento\Services;

use App\Modules\Common\Services\LectorCsv;
use App\Modules\Servicios\Models\Especialidad;
use App\Modules\Talento\Models\Especialista;
use App\Modules\Users\Models\TipoDocumento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cargue masivo de especialistas desde un CSV (separado por comas o punto y coma).
 *
 * Columnas: tipo_documento, numero_documento, nombres, apellidos, registro_profesional,
 * correo, telefono, especialidades (nombres o códigos separados por "|").
 *
 * Si el documento ya existe, actualiza los datos y sus especialidades quedan
 * exactamente como vienen en el archivo. No se puede quitar una especialidad que el
 * profesional tenga en agenda vigente. Las filas con errores se omiten y se reportan.
 */
class ImportadorEspecialistas
{
    public const COLUMNAS = ['tipo_documento', 'numero_documento', 'nombres', 'apellidos', 'registro_profesional', 'correo', 'telefono', 'especialidades'];

    private const COLUMNAS_REQUERIDAS = ['tipo_documento', 'numero_documento', 'nombres', 'apellidos', 'especialidades'];

    private const MAXIMO_FILAS = 2000;

    public function __construct(
        private readonly LectorCsv $lector
    ) {}

    /**
     * @return array{total: int, creados: int, actualizados: int, errores: list<array{fila: int, documento: string, mensajes: list<string>}>, simulado: bool}
     *
     * @throws ValidationException si el archivo no tiene la estructura esperada.
     */
    public function importar(string $ruta, bool $simular = false): array
    {
        $filas = $this->lector->leer($ruta, self::COLUMNAS_REQUERIDAS, self::MAXIMO_FILAS);
        $tipos = TipoDocumento::where('activo', true)->pluck('id', 'codigo')->mapWithKeys(fn ($id, $codigo) => [Str::upper($codigo) => $id]);
        $especialidades = Especialidad::where('activo', true)->get(['id', 'codigo', 'nombre']);
        $porClave = $especialidades->mapWithKeys(fn ($e) => [$e->codigo => $e->id])
            ->union($especialidades->mapWithKeys(fn ($e) => [Str::slug($e->nombre) => $e->id]));

        $resultado = ['total' => count($filas), 'creados' => 0, 'actualizados' => 0, 'errores' => [], 'simulado' => $simular];
        $vistos = [];

        $procesar = function () use ($filas, $tipos, $porClave, $simular, &$resultado, &$vistos) {
            foreach ($filas as $numero => $fila) {
                $documento = preg_replace('/[\s.]/', '', $fila['numero_documento'] ?? '');
                $tipo = Str::upper(trim($fila['tipo_documento'] ?? ''));
                $mensajes = [];

                $tipoId = $tipos[$tipo] ?? null;
                if (! $tipoId) {
                    $mensajes[] = $tipo === '' ? 'Falta el tipo de documento.' : "El tipo de documento \"{$tipo}\" no existe (usa CC, CE, PAS, PEP, PPT…).";
                }

                $validador = Validator::make(
                    ['numero_documento' => $documento] + $fila,
                    [
                        'numero_documento' => ['required', 'regex:/^[A-Za-z0-9]{3,20}$/'],
                        'nombres' => ['required', 'max:100'],
                        'apellidos' => ['required', 'max:100'],
                        'registro_profesional' => ['nullable', 'max:30'],
                        'correo' => ['nullable', 'email', 'max:150'],
                        'telefono' => ['nullable', 'max:30'],
                    ],
                    [
                        'numero_documento.required' => 'Falta el número de documento.',
                        'numero_documento.regex' => 'El documento admite entre 3 y 20 letras o números.',
                        'nombres.required' => 'Faltan los nombres.',
                        'apellidos.required' => 'Faltan los apellidos.',
                        'correo.email' => 'El correo no tiene un formato válido.',
                        'max' => 'El campo :attribute es demasiado largo.',
                    ]
                );
                $mensajes = [...$mensajes, ...$validador->errors()->all()];

                $especialidadIds = [];
                $nombresEspecialidades = array_filter(array_map('trim', preg_split('/[|,]/', $fila['especialidades'] ?? '')));
                if (! $nombresEspecialidades) {
                    $mensajes[] = 'Indica al menos una especialidad.';
                }
                foreach ($nombresEspecialidades as $nombre) {
                    $id = $porClave[Str::slug($nombre)] ?? null;
                    if ($id) {
                        $especialidadIds[] = $id;
                    } else {
                        $mensajes[] = "La especialidad \"{$nombre}\" no existe o está inactiva.";
                    }
                }

                $clave = "{$tipo}-{$documento}";
                if ($documento !== '' && isset($vistos[$clave])) {
                    $mensajes[] = "El documento está repetido en el archivo (fila {$vistos[$clave]}).";
                }
                $vistos[$clave] ??= $numero;

                $existente = $tipoId ? Especialista::withTrashed()->where('tipo_documento_id', $tipoId)->where('numero_documento', $documento)->first() : null;
                if ($existente?->trashed()) {
                    $mensajes[] = 'El especialista está eliminado. Restáuralo antes de volver a cargarlo.';
                } elseif ($existente && $especialidadIds) {
                    $enUso = $existente->agendas()->vigentes()->whereNotIn('especialidad_id', $especialidadIds)
                        ->with('especialidad:id,nombre')->get()->pluck('especialidad.nombre')->unique();
                    if ($enUso->isNotEmpty()) {
                        $mensajes[] = 'Tiene agenda vigente de '.$enUso->join(', ', ' y ').'; inclúyela en el archivo o termina esas franjas primero.';
                    }
                }

                if ($mensajes) {
                    $resultado['errores'][] = ['fila' => $numero, 'documento' => trim("{$tipo} {$documento}"), 'mensajes' => array_values(array_unique($mensajes))];

                    continue;
                }

                $datos = [
                    'tipo_documento_id' => $tipoId,
                    'numero_documento' => $documento,
                    'nombres' => LectorCsv::nombrePropio($fila['nombres']),
                    'apellidos' => LectorCsv::nombrePropio($fila['apellidos']),
                    'registro_profesional' => LectorCsv::opcional($fila['registro_profesional'] ?? null),
                    'correo' => LectorCsv::opcional(Str::lower($fila['correo'] ?? '')),
                    'telefono' => LectorCsv::opcional($fila['telefono'] ?? null),
                ];

                $resultado[$existente ? 'actualizados' : 'creados']++;
                if ($simular) {
                    continue;
                }

                $especialista = $existente ?? new Especialista(['activo' => true]);
                $especialista->fill($datos)->save();
                $especialista->especialidades()->sync(array_unique($especialidadIds));
            }
        };

        $simular ? $procesar() : DB::transaction($procesar);

        return $resultado;
    }
}
