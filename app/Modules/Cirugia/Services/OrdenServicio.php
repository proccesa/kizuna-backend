<?php

namespace App\Modules\Cirugia\Services;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Catalogos\Models\Municipio;
use App\Modules\Cirugia\Models\OrdenQuirurgica;
use App\Modules\Citas\Models\Cita;
use App\Modules\Citas\Services\DisponibilidadServicio;
use App\Modules\Common\Services\LectorCsv;
use App\Modules\Contratacion\Models\Contrato;
use App\Modules\Contratacion\Models\Paciente;
use App\Modules\Servicios\Models\Especialidad;
use App\Modules\Talento\Models\Especialista;
use App\Modules\Users\Models\TipoDocumento;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Flujo de una orden quirúrgica: registro → validación de especialidad → cita de pre-anestesia → concepto.
 */
class OrdenServicio
{
    private const RELACIONES = [
        'paciente:id,tipo_documento_id,numero_documento,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido,fecha_nacimiento,sexo,telefono,correo',
        'paciente.tipoDocumento:id,codigo',
        'cups:id,codigo,nombre,es_quirurgico',
        'especialidad:id,codigo,nombre',
        'cirujano:id,nombres,apellidos',
        'cirugia',
        'cirugia.sala:id,codigo,nombre',
        'cirugia.cirujano:id,nombres,apellidos',
        'contrato:id,numero,entidad_id,modalidad_contratacion_id',
        'contrato.entidad:id,razon_social,sigla',
        'citaActual.especialista:id,nombres,apellidos',
        'citaActual.sede:id,nombre',
    ];

    public function __construct(
        private readonly ReglasPreanestesia $reglas,
        private readonly DisponibilidadServicio $disponibilidad
    ) {}

    public function listar(array $filtros = [], int $porPagina = 20): LengthAwarePaginator
    {
        $query = OrdenQuirurgica::with(self::RELACIONES);

        if (! empty($filtros['estado'])) {
            $estado = strtoupper($filtros['estado']);
            match ($estado) {
                'AVAL_VENCIDO' => $query->where('estado', 'APTA')->whereDate('aval_hasta', '<', now()->toDateString()),
                'APTA' => $query->conAvalVigente(),
                default => $query->where('estado', $estado),
            };
        }

        foreach (['especialidad_id', 'contrato_id', 'paciente_id'] as $campo) {
            if (! empty($filtros[$campo])) {
                $query->where($campo, (int) $filtros[$campo]);
            }
        }

        if (! empty($filtros['prioridad'])) {
            $query->where('prioridad', strtoupper($filtros['prioridad']));
        }

        if (! empty($filtros['buscar'])) {
            $termino = trim($filtros['buscar']);
            $like = '%'.mb_strtolower($termino).'%';
            $query->where(function ($q) use ($termino, $like) {
                $q->where('referencia_externa', 'like', "%{$termino}%")
                    ->orWhereHas('cups', fn ($c) => $c->where('codigo', 'like', "{$termino}%"))
                    ->orWhereHas('paciente', fn ($p) => $p->where('numero_documento', 'like', "{$termino}%")
                        ->orWhereRaw('LOWER(primer_nombre) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(primer_apellido) LIKE ?', [$like]));
            });
        }

        return $query->orderByRaw("CASE WHEN prioridad = 'PRIORITARIA' THEN 0 ELSE 1 END")
            ->orderByDesc('fecha_orden')->orderByDesc('id')
            ->paginate(max(1, min($porPagina, 100)));
    }

    /**
     * Conteo por estado para las pestañas.
     *
     * @return array<string, int>
     */
    public function resumen(): array
    {
        $conteos = OrdenQuirurgica::query()->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado')->map(fn ($n) => (int) $n);
        $vencidas = OrdenQuirurgica::where('estado', 'APTA')->whereDate('aval_hasta', '<', now()->toDateString())->count();

        return collect(OrdenQuirurgica::ESTADOS)->mapWithKeys(fn ($e) => [$e => $conteos[$e] ?? 0])->all()
            + ['APTA' => ($conteos['APTA'] ?? 0) - $vencidas, 'AVAL_VENCIDO' => $vencidas, 'TOTAL' => $conteos->sum()];
    }

    /**
     * @throws ModelNotFoundException
     */
    public function obtenerPorId(int $id): OrdenQuirurgica
    {
        $orden = OrdenQuirurgica::withTrashed()->with([
            ...self::RELACIONES,
            'paciente.municipio:id,codigo,nombre',
            'citas' => fn ($q) => $q->with(['especialista:id,nombres,apellidos', 'sede:id,nombre'])->orderByDesc('fecha')->orderByDesc('hora_inicio'),
            'historia:id,estado,finalizada_en,origen,profesional_externo,resultado,especialista_id',
            'historia.especialista:id,nombres,apellidos',
        ])->find($id);

        if (! $orden) {
            throw new ModelNotFoundException("No se encontró la orden con ID: {$id}");
        }

        return $orden;
    }

    /**
     * Registra la orden (o devuelve la existente si la referencia externa ya llegó antes),
     * valida que la IPS tenga la especialidad del CUPS y asigna la cita de pre-anestesia.
     *
     * @return array{orden: OrdenQuirurgica, duplicada: bool}
     */
    public function registrar(array $datos, string $origen, ?string $sistema = null): array
    {
        if (! empty($datos['referencia_externa'])) {
            $existente = OrdenQuirurgica::where('sistema_origen', $sistema)->where('referencia_externa', $datos['referencia_externa'])->first();
            if ($existente) {
                return ['orden' => $this->obtenerPorId($existente->id), 'duplicada' => true];
            }
        }

        $orden = DB::transaction(function () use ($datos, $origen, $sistema) {
            $paciente = $this->guardarPaciente($datos['paciente']);
            $cups = Cups::where('codigo', $datos['cups'])->firstOrFail();
            $especialidad = $this->especialidadQueAtiende($cups);

            return OrdenQuirurgica::create([
                'paciente_id' => $paciente->id,
                'contrato_id' => $this->resolverContrato($datos),
                'cups_id' => $cups->id,
                'especialidad_id' => $especialidad['especialidad']?->id,
                'cirujano_id' => ! empty($datos['cirujano_documento'])
                    ? Especialista::where('numero_documento', preg_replace('/[\s.]/', '', $datos['cirujano_documento']))->where('activo', true)->value('id')
                    : null,
                'diagnostico_cie10' => isset($datos['diagnostico_cie10']) ? strtoupper($datos['diagnostico_cie10']) : null,
                'diagnostico' => $datos['diagnostico'] ?? null,
                'prioridad' => strtoupper($datos['prioridad'] ?? 'ELECTIVA'),
                'fecha_orden' => $datos['fecha_orden'] ?? now()->toDateString(),
                'medico_ordenante' => $datos['medico_ordenante'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
                'origen' => $origen,
                'sistema_origen' => $sistema,
                'referencia_externa' => $datos['referencia_externa'] ?? null,
                'estado' => $especialidad['especialidad'] ? 'PENDIENTE_CITA' : 'RECHAZADA',
                'motivo_estado' => $especialidad['motivo'],
            ]);
        });

        if ($orden->estado === 'PENDIENTE_CITA') {
            $this->asignarCita($orden);
        }

        return ['orden' => $this->obtenerPorId($orden->id), 'duplicada' => false];
    }

    /**
     * Busca el primer cupo de anestesiología y agenda la cita. Si no hay, la orden queda pendiente.
     */
    public function asignarCita(OrdenQuirurgica $orden, ?array $cupoElegido = null, string $origen = 'AUTOMATICA'): OrdenQuirurgica
    {
        $reglas = $this->reglas->todas();
        $especialidad = $this->reglas->especialidad();
        $cupsConsulta = $this->reglas->cupsConsulta();

        if (! $especialidad || ! $cupsConsulta) {
            $orden->update(['estado' => 'PENDIENTE_CITA', 'motivo_estado' => 'Configura la especialidad y el CUPS de la consulta pre-anestésica.']);

            return $orden;
        }

        $sedeIds = $this->sedesPermitidas($orden);
        $desde = now()->startOfDay()->addDays((int) $reglas['dias_anticipacion']);
        $hasta = $desde->copy()->addDays((int) $reglas['horizonte_dias']);

        $cupos = $this->disponibilidad->cupos($especialidad->id, $cupsConsulta->id, $cupoElegido ? Carbon::parse($cupoElegido['fecha']) : $desde, $cupoElegido ? Carbon::parse($cupoElegido['fecha']) : $hasta, $sedeIds, (int) $reglas['duracion_minutos'], $cupoElegido ? 500 : 1);

        $cupo = $cupoElegido
            ? $cupos->first(fn ($c) => $c['especialista_id'] === (int) $cupoElegido['especialista_id'] && $c['hora_inicio'] === $cupoElegido['hora_inicio'] && $c['sede_id'] === (int) $cupoElegido['sede_id'])
            : $cupos->first();

        if (! $cupo) {
            if ($cupoElegido) {
                throw ValidationException::withMessages(['hora_inicio' => ['Ese cupo ya no está disponible. Elige otro.']]);
            }
            $orden->update([
                'estado' => 'PENDIENTE_CITA',
                'motivo_estado' => "Sin cupo de {$especialidad->nombre} en los próximos {$reglas['horizonte_dias']} días".($sedeIds ? ' en las sedes del contrato' : '').'.',
            ]);

            return $orden;
        }

        DB::transaction(function () use ($orden, $cupo, $especialidad, $cupsConsulta, $origen) {
            // Evita dos citas en el mismo cupo si dos procesos asignan a la vez.
            $ocupado = Cita::where('especialista_id', $cupo['especialista_id'])->whereDate('fecha', $cupo['fecha'])->where('estado', '!=', 'CANCELADA')
                ->where('hora_inicio', '<', $cupo['hora_fin'])->where('hora_fin', '>', $cupo['hora_inicio'])->lockForUpdate()->exists();
            if ($ocupado) {
                throw ValidationException::withMessages(['hora_inicio' => ['Ese cupo acaba de ser tomado. Intenta de nuevo.']]);
            }

            $orden->citas()->where('estado', 'PROGRAMADA')->update(['estado' => 'CANCELADA', 'motivo_cancelacion' => 'Reprogramada']);
            Cita::create([
                'paciente_id' => $orden->paciente_id,
                'especialista_id' => $cupo['especialista_id'],
                'sede_id' => $cupo['sede_id'],
                'especialidad_id' => $especialidad->id,
                'cups_id' => $cupsConsulta->id,
                'agenda_id' => $cupo['agenda_id'],
                'orden_id' => $orden->id,
                'fecha' => $cupo['fecha'],
                'hora_inicio' => $cupo['hora_inicio'],
                'hora_fin' => $cupo['hora_fin'],
                'consultorio' => $cupo['consultorio'],
                'tipo' => 'PREANESTESIA',
                'estado' => 'PROGRAMADA',
                'origen' => $origen,
            ]);
            $orden->update(['estado' => 'CITA_ASIGNADA', 'motivo_estado' => null]);
        });

        return $orden->refresh();
    }

    /**
     * Cupos disponibles para reprogramar la cita de una orden.
     */
    public function cupos(int $id, ?string $desde = null, int $limite = 30): array
    {
        $orden = $this->buscar($id);
        $reglas = $this->reglas->todas();
        $especialidad = $this->reglas->especialidad();
        $cupsConsulta = $this->reglas->cupsConsulta();
        if (! $especialidad || ! $cupsConsulta) {
            return [];
        }

        $inicio = max(now()->startOfDay()->addDays((int) $reglas['dias_anticipacion']), $desde ? Carbon::parse($desde)->startOfDay() : now()->startOfDay());

        return $this->disponibilidad->cupos($especialidad->id, $cupsConsulta->id, $inicio, $inicio->copy()->addDays((int) $reglas['horizonte_dias']), $this->sedesPermitidas($orden), (int) $reglas['duracion_minutos'], $limite)->all();
    }

    /**
     * El operador mueve la cita a un cupo elegido.
     *
     * @throws ValidationException
     */
    public function reprogramar(int $id, array $cupo): OrdenQuirurgica
    {
        $orden = $this->buscar($id);
        if (! in_array($orden->estado, ['PENDIENTE_CITA', 'CITA_ASIGNADA', 'APLAZADA'], true)) {
            throw ValidationException::withMessages(['estado' => ['Solo se reprograman órdenes pendientes de valoración.']]);
        }

        $this->asignarCita($orden, $cupo, 'MANUAL');

        return $this->obtenerPorId($id);
    }

    /**
     * Intenta asignar cita a todas las órdenes pendientes (prioritarias y antiguas primero).
     *
     * @return array{revisadas: int, asignadas: int}
     */
    public function asignarPendientes(): array
    {
        $pendientes = OrdenQuirurgica::where('estado', 'PENDIENTE_CITA')
            ->orderByRaw("CASE WHEN prioridad = 'PRIORITARIA' THEN 0 ELSE 1 END")->orderBy('fecha_orden')->orderBy('id')->get();

        $asignadas = 0;
        foreach ($pendientes as $orden) {
            if ($this->asignarCita($orden)->estado === 'CITA_ASIGNADA') {
                $asignadas++;
            }
        }

        return ['revisadas' => $pendientes->count(), 'asignadas' => $asignadas];
    }

    /**
     * Revalida las órdenes rechazadas (p. ej. después de asignar la especialidad del CUPS o contratar al especialista).
     *
     * @throws ValidationException
     */
    public function revalidar(int $id): OrdenQuirurgica
    {
        $orden = $this->buscar($id);
        if ($orden->estado !== 'RECHAZADA') {
            throw ValidationException::withMessages(['estado' => ['Solo se revalidan órdenes rechazadas.']]);
        }

        $resultado = $this->especialidadQueAtiende($orden->cups);
        if (! $resultado['especialidad']) {
            $orden->update(['motivo_estado' => $resultado['motivo']]);

            throw ValidationException::withMessages(['especialidad_id' => [$resultado['motivo']]]);
        }

        $orden->update(['especialidad_id' => $resultado['especialidad']->id, 'estado' => 'PENDIENTE_CITA', 'motivo_estado' => null]);
        $this->asignarCita($orden);

        return $this->obtenerPorId($id);
    }

    /**
     * @throws ValidationException
     */
    public function cancelar(int $id, string $motivo): OrdenQuirurgica
    {
        $orden = $this->buscar($id);
        if (in_array($orden->estado, ['APTA', 'NO_APTA', 'CANCELADA'], true)) {
            throw ValidationException::withMessages(['estado' => ['La orden ya tiene concepto o está cancelada.']]);
        }

        DB::transaction(function () use ($orden, $motivo) {
            $orden->citas()->where('estado', 'PROGRAMADA')->update(['estado' => 'CANCELADA', 'motivo_cancelacion' => 'Orden cancelada']);
            $orden->update(['estado' => 'CANCELADA', 'motivo_estado' => $motivo]);
        });

        return $this->obtenerPorId($id);
    }

    /**
     * Aplica el concepto de la valoración pre-anestésica a la orden.
     * APTO → aval vigente según ASA; NO_APTO → no se programa; APLAZADO → requiere nueva valoración.
     *
     * @param  int  $diasSuspension  Días de suspensión de medicamentos antes de poder operar.
     */
    public function aplicarConcepto(OrdenQuirurgica $orden, int $historiaId, string $concepto, ?int $asa, Carbon $fechaValoracion, int $diasSuspension = 0, ?string $avalHasta = null, ?string $motivo = null): OrdenQuirurgica
    {
        $apto = in_array($concepto, ['APTO', 'APTO_CON_RECOMENDACIONES'], true);
        $desde = $fechaValoracion->copy()->startOfDay();

        $orden->update([
            'historia_id' => $historiaId,
            'concepto' => $concepto,
            'asa' => $asa,
            'estado' => $apto ? 'APTA' : ($concepto === 'APLAZADO' ? 'APLAZADA' : 'NO_APTA'),
            'motivo_estado' => $apto ? null : $motivo,
            'aval_desde' => $apto ? $desde->toDateString() : null,
            'aval_hasta' => $apto ? ($avalHasta ?? $desde->copy()->addDays($this->reglas->vigenciaDias($asa))->toDateString()) : null,
            'programable_desde' => $apto ? $desde->copy()->addDays(max(1, $diasSuspension))->toDateString() : null,
        ]);
        $orden->citas()->where('estado', 'PROGRAMADA')->whereDate('fecha', '<=', $fechaValoracion->toDateString())->update(['estado' => 'ATENDIDA']);
        // Si la valoración llegó antes de la cita agendada (p. ej. desde otro sistema), se libera el cupo.
        $orden->citas()->where('estado', 'PROGRAMADA')->whereDate('fecha', '>', $fechaValoracion->toDateString())
            ->update(['estado' => 'CANCELADA', 'motivo_cancelacion' => 'Valoración pre-anestésica ya realizada']);

        return $orden;
    }

    /**
     * Especialidad de la IPS que atiende el CUPS: debe estar relacionada con el CUPS
     * y tener al menos un especialista activo.
     *
     * @return array{especialidad: ?Especialidad, motivo: ?string}
     */
    public function especialidadQueAtiende(Cups $cups): array
    {
        $especialidades = $cups->especialidades()->where('especialidades.activo', true)->get();
        if ($especialidades->isEmpty()) {
            return ['especialidad' => null, 'motivo' => "Ninguna especialidad de la IPS atiende el CUPS {$cups->codigo}."];
        }

        $conProfesionales = $especialidades->first(fn (Especialidad $e) => DB::table('especialidad_especialista')
            ->join('especialistas', 'especialistas.id', '=', 'especialidad_especialista.especialista_id')
            ->where('especialidad_especialista.especialidad_id', $e->id)
            ->where('especialistas.activo', true)->whereNull('especialistas.deleted_at')->exists());

        return $conProfesionales
            ? ['especialidad' => $conProfesionales, 'motivo' => null]
            : ['especialidad' => null, 'motivo' => 'No hay especialistas activos de '.$especialidades->pluck('nombre')->join(', ', ' ni ').' para el CUPS '.$cups->codigo.'.'];
    }

    /**
     * Busca un paciente por documento (para precargar el formulario manual).
     */
    public function pacientePorDocumento(string $tipo, string $numero): ?Paciente
    {
        $tipoId = TipoDocumento::where('codigo', strtoupper($tipo))->value('id');

        return $tipoId ? Paciente::with('municipio:id,codigo,nombre')->where('tipo_documento_id', $tipoId)->where('numero_documento', preg_replace('/[\s.]/', '', $numero))->first() : null;
    }

    private function guardarPaciente(array $datos): Paciente
    {
        $tipoId = TipoDocumento::where('codigo', strtoupper($datos['tipo_documento']))->value('id');
        $municipio = ! empty($datos['municipio']) ? Municipio::where('codigo', str_pad(preg_replace('/\D/', '', (string) $datos['municipio']), 5, '0', STR_PAD_LEFT))->value('id') : null;

        $valores = array_filter([
            'primer_nombre' => LectorCsv::nombrePropio($datos['primer_nombre']),
            'segundo_nombre' => LectorCsv::opcional(LectorCsv::nombrePropio($datos['segundo_nombre'] ?? '')),
            'primer_apellido' => LectorCsv::nombrePropio($datos['primer_apellido']),
            'segundo_apellido' => LectorCsv::opcional(LectorCsv::nombrePropio($datos['segundo_apellido'] ?? '')),
            'fecha_nacimiento' => $datos['fecha_nacimiento'],
            'sexo' => strtoupper($datos['sexo']),
            'telefono' => LectorCsv::opcional($datos['telefono'] ?? null),
            'correo' => LectorCsv::opcional(isset($datos['correo']) ? Str::lower($datos['correo']) : null),
            'direccion' => LectorCsv::opcional($datos['direccion'] ?? null),
            'municipio_id' => $municipio,
        ], fn ($v, $k) => $v !== null || in_array($k, ['segundo_nombre', 'segundo_apellido'], true), ARRAY_FILTER_USE_BOTH);

        return Paciente::updateOrCreate(
            ['tipo_documento_id' => $tipoId, 'numero_documento' => preg_replace('/[\s.]/', '', $datos['numero_documento'])],
            $valores
        );
    }

    private function resolverContrato(array $datos): ?int
    {
        if (! empty($datos['contrato_id'])) {
            return (int) $datos['contrato_id'];
        }
        if (! empty($datos['contrato_numero'])) {
            return Contrato::where('numero', $datos['contrato_numero'])->value('id');
        }

        return null;
    }

    /**
     * Si la orden tiene contrato con sedes, la cita se busca solo en esas sedes.
     *
     * @return list<int>|null
     */
    private function sedesPermitidas(OrdenQuirurgica $orden): ?array
    {
        if (! $orden->contrato_id) {
            return null;
        }
        $sedes = DB::table('contrato_sede')->where('contrato_id', $orden->contrato_id)->pluck('sede_id')->map(fn ($id) => (int) $id)->all();

        return $sedes ?: null;
    }

    private function buscar(int $id): OrdenQuirurgica
    {
        $orden = OrdenQuirurgica::find($id);
        if (! $orden) {
            throw new ModelNotFoundException("No se encontró la orden con ID: {$id}");
        }

        return $orden;
    }
}
