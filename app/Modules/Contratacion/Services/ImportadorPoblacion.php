<?php

namespace App\Modules\Contratacion\Services;

use App\Modules\Catalogos\Models\Municipio;
use App\Modules\Common\Services\LectorCsv;
use App\Modules\Contratacion\Models\Poblacion;
use App\Modules\Contratacion\Models\PoblacionCargue;
use App\Modules\Users\Models\TipoDocumento;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cargue de pacientes de una población desde CSV.
 *
 * Columnas: tipo_documento, numero_documento, primer_nombre, segundo_nombre, primer_apellido,
 * segundo_apellido, fecha_nacimiento, sexo, telefono, correo, direccion, municipio (DANE), cohortes.
 *
 * Modos:
 * - REEMPLAZAR (cargue del mes): el archivo es la población completa. Quien no venga queda retirado.
 *   Las filas con errores no retiran al paciente si ya estaba en la población.
 * - AGREGAR: suma o actualiza pacientes sin retirar a nadie.
 *
 * Valida sin consultar la base fila por fila, para soportar archivos de decenas de miles de filas.
 */
class ImportadorPoblacion
{
    public const COLUMNAS = ['tipo_documento', 'numero_documento', 'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido', 'fecha_nacimiento', 'sexo', 'telefono', 'correo', 'direccion', 'municipio', 'cohortes'];

    private const COLUMNAS_REQUERIDAS = ['tipo_documento', 'numero_documento', 'primer_nombre', 'primer_apellido', 'fecha_nacimiento', 'sexo'];

    private const MAXIMO_FILAS = 100000;

    /** Errores que se devuelven en detalle; el total siempre se informa. */
    private const MAXIMO_ERRORES_DETALLE = 500;

    private const SEXOS = ['F' => 'F', 'M' => 'M', 'I' => 'I', 'FEMENINO' => 'F', 'MASCULINO' => 'M', 'MUJER' => 'F', 'HOMBRE' => 'M', 'H' => 'M', 'INDETERMINADO' => 'I'];

    public function __construct(
        private readonly LectorCsv $lector
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function importar(Poblacion $poblacion, string $ruta, string $nombreArchivo, bool $simular, string $modo = 'REEMPLAZAR', ?int $usuarioId = null): array
    {
        $filas = $this->lector->leer($ruta, self::COLUMNAS_REQUERIDAS, self::MAXIMO_FILAS);
        $tipos = TipoDocumento::where('activo', true)->pluck('id', 'codigo')->mapWithKeys(fn ($id, $codigo) => [Str::upper($codigo) => $id]);
        $municipios = Municipio::pluck('id', 'codigo');
        $hoy = now()->toDateString();

        $validas = [];
        $errores = [];
        $conErrores = 0;
        $clavesConError = [];
        $vistos = [];

        foreach ($filas as $numero => $fila) {
            $mensajes = [];
            $tipo = Str::upper($fila['tipo_documento'] ?? '');
            $documento = preg_replace('/[\s.]/', '', $fila['numero_documento'] ?? '');
            $tipoId = $tipos[$tipo] ?? null;

            if (! $tipoId) {
                $mensajes[] = $tipo === '' ? 'Falta el tipo de documento.' : "El tipo de documento \"{$tipo}\" no existe.";
            }
            if (! preg_match('/^[A-Za-z0-9]{3,20}$/', $documento)) {
                $mensajes[] = $documento === '' ? 'Falta el número de documento.' : 'El documento admite entre 3 y 20 letras o números.';
            }
            foreach (['primer_nombre' => 'el primer nombre', 'primer_apellido' => 'el primer apellido'] as $campo => $nombre) {
                if (($fila[$campo] ?? '') === '') {
                    $mensajes[] = "Falta {$nombre}.";
                }
            }
            foreach (['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'] as $campo) {
                if (mb_strlen($fila[$campo] ?? '') > 60) {
                    $mensajes[] = 'Los nombres y apellidos admiten máximo 60 caracteres.';
                    break;
                }
            }

            $nacimiento = $this->fecha($fila['fecha_nacimiento'] ?? '');
            if (! $nacimiento) {
                $mensajes[] = 'La fecha de nacimiento no es válida (usa AAAA-MM-DD o DD/MM/AAAA).';
            } elseif ($nacimiento > $hoy || $nacimiento < '1900-01-01') {
                $mensajes[] = 'La fecha de nacimiento está fuera de rango.';
            }

            $sexo = self::SEXOS[Str::upper(Str::ascii($fila['sexo'] ?? ''))] ?? null;
            if (! $sexo) {
                $mensajes[] = 'El sexo debe ser F, M o I.';
            }

            $correo = LectorCsv::opcional(Str::lower($fila['correo'] ?? ''));
            if ($correo && ! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $mensajes[] = 'El correo no tiene un formato válido.';
            }

            $municipioId = null;
            $codigoMunicipio = preg_replace('/\D/', '', $fila['municipio'] ?? '');
            if ($codigoMunicipio !== '') {
                $municipioId = $municipios[str_pad($codigoMunicipio, 5, '0', STR_PAD_LEFT)] ?? null;
                if (! $municipioId) {
                    $mensajes[] = "El municipio \"{$fila['municipio']}\" no es un código DIVIPOLA válido.";
                }
            }

            $cohortes = array_values(array_unique(array_filter(array_map(fn ($c) => Str::limit(trim($c), 60, ''), explode('|', $fila['cohortes'] ?? '')))));
            if (count($cohortes) > 10) {
                $mensajes[] = 'Máximo 10 cohortes por paciente.';
            }

            $clave = "{$tipoId}-{$documento}";
            if ($tipoId && $documento !== '' && isset($vistos[$clave])) {
                $mensajes[] = "El documento está repetido en el archivo (fila {$vistos[$clave]}).";
            }

            if ($mensajes) {
                $conErrores++;
                if ($tipoId && $documento !== '') {
                    $clavesConError[$clave] = true;
                }
                if (count($errores) < self::MAXIMO_ERRORES_DETALLE) {
                    $errores[] = ['fila' => $numero, 'documento' => trim("{$tipo} {$documento}"), 'mensajes' => array_values(array_unique($mensajes))];
                }

                continue;
            }

            $vistos[$clave] = $numero;
            $validas[$clave] = [
                'tipo_documento_id' => $tipoId,
                'numero_documento' => $documento,
                'primer_nombre' => LectorCsv::nombrePropio($fila['primer_nombre']),
                'segundo_nombre' => LectorCsv::opcional(LectorCsv::nombrePropio($fila['segundo_nombre'] ?? '')),
                'primer_apellido' => LectorCsv::nombrePropio($fila['primer_apellido']),
                'segundo_apellido' => LectorCsv::opcional(LectorCsv::nombrePropio($fila['segundo_apellido'] ?? '')),
                'fecha_nacimiento' => $nacimiento,
                'sexo' => $sexo,
                'telefono' => LectorCsv::opcional(Str::limit($fila['telefono'] ?? '', 30, '')),
                'correo' => $correo,
                'direccion' => LectorCsv::opcional(Str::limit($fila['direccion'] ?? '', 255, '')),
                'municipio_id' => $municipioId,
                '_cohortes' => $cohortes,
            ];
        }

        // Estado actual de la población, para contar nuevos, actualizados y retirados.
        $pacientesPorClave = $this->idsPacientes(array_keys($validas + $clavesConError));
        $enPoblacion = DB::table('poblacion_paciente')->where('poblacion_id', $poblacion->id)->pluck('activo', 'paciente_id')->map(fn ($a) => (bool) $a);
        $activosAntes = $enPoblacion->filter()->count();

        $actualizados = 0;
        $activosQueSiguen = [];
        foreach ($validas as $clave => $_) {
            $pacienteId = $pacientesPorClave[$clave] ?? null;
            if ($pacienteId && ($enPoblacion[$pacienteId] ?? false)) {
                $actualizados++;
                $activosQueSiguen[$pacienteId] = true;
            }
        }
        // Filas con error de pacientes que ya estaban: no se retiran.
        $protegidos = [];
        foreach (array_keys($clavesConError) as $clave) {
            $pacienteId = $pacientesPorClave[$clave] ?? null;
            if ($pacienteId && ($enPoblacion[$pacienteId] ?? false)) {
                $protegidos[$pacienteId] = true;
            }
        }

        $nuevos = count($validas) - $actualizados;
        $retirados = $modo === 'REEMPLAZAR' ? max(0, $activosAntes - count($activosQueSiguen) - count(array_diff_key($protegidos, $activosQueSiguen))) : 0;

        $resultado = [
            'total' => count($filas),
            'nuevos' => $nuevos,
            'actualizados' => $actualizados,
            'retirados' => $retirados,
            'con_errores' => $conErrores,
            'errores' => $errores,
            'modo' => $modo,
            'simulado' => $simular,
            'pacientes_antes' => $activosAntes,
            'pacientes_despues' => $modo === 'REEMPLAZAR' ? count($validas) + count(array_diff_key($protegidos, $activosQueSiguen)) : $activosAntes + $nuevos,
            'advertencia' => $modo === 'REEMPLAZAR' && $activosAntes > 0 && $retirados > $activosAntes / 2
                ? "Se retirarán {$retirados} de {$activosAntes} pacientes, más de la mitad. Verifica que el archivo esté completo."
                : null,
        ];

        if ($simular) {
            return $resultado;
        }

        if (! $validas) {
            throw ValidationException::withMessages(['archivo' => ['El archivo no tiene filas válidas para cargar.']]);
        }

        DB::transaction(function () use ($poblacion, $validas, $modo, $protegidos, $resultado, $nombreArchivo, $usuarioId) {
            $ahora = now();

            foreach (array_chunk($validas, 500, true) as $lote) {
                DB::table('pacientes')->upsert(
                    array_map(fn ($p) => array_diff_key($p, ['_cohortes' => 1]) + ['created_at' => $ahora, 'updated_at' => $ahora], array_values($lote)),
                    ['tipo_documento_id', 'numero_documento'],
                    ['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido', 'fecha_nacimiento', 'sexo', 'telefono', 'correo', 'direccion', 'municipio_id', 'updated_at']
                );
            }
            $ids = $this->idsPacientes(array_keys($validas));

            if ($modo === 'REEMPLAZAR') {
                DB::table('poblacion_paciente')->where('poblacion_id', $poblacion->id)->where('activo', true)
                    ->whereNotIn('paciente_id', array_keys($protegidos) ?: [0])
                    ->update(['activo' => false, 'updated_at' => $ahora]);
            }

            foreach (array_chunk($validas, 500, true) as $lote) {
                DB::table('poblacion_paciente')->upsert(
                    collect($lote)->map(fn ($p, $clave) => [
                        'poblacion_id' => $poblacion->id,
                        'paciente_id' => $ids[$clave],
                        'cohortes' => $p['_cohortes'] ? json_encode($p['_cohortes'], JSON_UNESCAPED_UNICODE) : null,
                        'activo' => true,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ])->values()->all(),
                    ['poblacion_id', 'paciente_id'],
                    ['cohortes', 'activo', 'updated_at']
                );
            }

            $poblacion->update(['ultimo_cargue_en' => $ahora]);
            PoblacionCargue::create([
                'poblacion_id' => $poblacion->id,
                'usuario_id' => $usuarioId,
                'archivo' => Str::limit($nombreArchivo, 250, ''),
                'modo' => $modo,
                'total' => $resultado['total'],
                'nuevos' => $resultado['nuevos'],
                'actualizados' => $resultado['actualizados'],
                'retirados' => $resultado['retirados'],
                'con_errores' => $resultado['con_errores'],
            ]);
        });

        return $resultado;
    }

    /**
     * Ids de pacientes existentes por clave "tipoId-documento", consultando en lotes.
     *
     * @param  list<string>  $claves
     * @return array<string, int>
     */
    private function idsPacientes(array $claves): array
    {
        $porTipo = [];
        foreach ($claves as $clave) {
            [$tipoId, $documento] = explode('-', $clave, 2);
            if ($tipoId !== '' && $documento !== '') {
                $porTipo[$tipoId][] = $documento;
            }
        }

        $ids = [];
        foreach ($porTipo as $tipoId => $documentos) {
            foreach (array_chunk($documentos, 1000) as $lote) {
                DB::table('pacientes')->where('tipo_documento_id', $tipoId)->whereIn('numero_documento', $lote)
                    ->pluck('id', 'numero_documento')
                    ->each(function ($id, $documento) use (&$ids, $tipoId) {
                        $ids["{$tipoId}-{$documento}"] = (int) $id;
                    });
            }
        }

        return $ids;
    }

    /**
     * Acepta AAAA-MM-DD, DD/MM/AAAA y DD-MM-AAAA.
     */
    private function fecha(string $valor): ?string
    {
        $valor = trim($valor);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'j/n/Y', 'j-n-Y'] as $formato) {
            try {
                $fecha = Carbon::createFromFormat("!{$formato}", $valor);
                if ($fecha && $fecha->format($formato) === $valor) {
                    return $fecha->toDateString();
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
