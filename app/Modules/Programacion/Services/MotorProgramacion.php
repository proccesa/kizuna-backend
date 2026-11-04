<?php

namespace App\Modules\Programacion\Services;

use App\Modules\Cirugia\Models\OrdenQuirurgica;
use App\Modules\Cirugia\Services\ReglasPreanestesia;
use App\Modules\Citas\Models\Cita;
use App\Modules\Inventario\Models\Item;
use App\Modules\Inventario\Models\Reserva;
use App\Modules\Inventario\Services\VerificadorRecursos;
use App\Modules\Programacion\Models\AsignacionAnestesia;
use App\Modules\Programacion\Models\Cirugia;
use App\Modules\Programacion\Models\Corrida;
use App\Modules\Servicios\Models\PortafolioItem;
use App\Modules\Talento\Models\Agenda;
use App\Modules\Talento\Models\Ausencia;
use App\Modules\Talento\Models\Especialista;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Motor de programación quirúrgica.
 *
 * 1. Toma las órdenes aptas con aval vigente y sin cirugía, y las ordena por puntaje de prioridad.
 * 2. Para cada una busca, dentro de la ventana del aval (desde "programable desde" + anticipación
 *    hasta el vencimiento del aval), en las sedes del contrato más cercanas al paciente:
 *    - un cirujano de la especialidad con agenda ese día (el de la orden si viene);
 *    - una sala con equipos, cajas e insumos disponibles (VerificadorRecursos);
 *    - el anestesiólogo de esa sala en esa jornada (o uno libre, que queda asignado al bloque).
 * 3. Reserva los recursos y deja la cirugía PROPUESTA. El jefe de cirugía aprueba o descarta la propuesta.
 */
class MotorProgramacion
{
    /** @var array<string, int> carga de cirugías por cirujano en el periodo (para repartir) */
    private array $carga = [];

    /*
     * Cachés de la corrida, para no repetir miles de consultas iguales. Agendas y ausencias no cambian
     * durante la corrida; la ocupación de cada profesional se actualiza al crear cada cirugía, y las
     * verificaciones de recursos se invalidan en cuanto se reserva algo.
     */

    /** @var Collection<int, Collection<int, Agenda>> agendas activas por especialista */
    private Collection $agendas;

    /** @var Collection<int, Collection<int, Ausencia>> ausencias por especialista */
    private Collection $ausencias;

    /** @var array<string, Collection> franjas ocupadas por "especialista|fecha" */
    private array $ocupacion = [];

    /** @var array<string, array> resultado de VerificadorRecursos por "cups|sede|inicio|fin" */
    private array $verificaciones = [];

    public function __construct(
        private readonly ReglasProgramacion $reglas,
        private readonly PrioridadServicio $prioridad,
        private readonly VerificadorRecursos $verificador,
        private readonly ReglasPreanestesia $reglasPreanestesia
    ) {}

    /**
     * @param  list<int>|null  $sedeIds
     *
     * @throws ValidationException si ya hay una propuesta pendiente.
     */
    public function generar(Carbon $desde, Carbon $hasta, ?array $sedeIds, ?int $usuarioId): Corrida
    {
        if (Corrida::where('estado', 'PROPUESTA')->exists()) {
            throw ValidationException::withMessages(['corrida' => ['Ya hay una propuesta pendiente. Apruébala o descártala antes de generar otra.']]);
        }

        // Con cientos de órdenes la corrida supera los 30 s por defecto de PHP.
        set_time_limit(300);

        $reglas = $this->reglas->todas();
        $hoy = now()->startOfDay();

        $ordenes = OrdenQuirurgica::conAvalVigente()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('cirugias')->whereColumn('cirugias.orden_id', 'ordenes_quirurgicas.id')->whereIn('cirugias.estado', ['PROPUESTA', 'APROBADA']))
            ->whereDate('programable_desde', '<=', $hasta->toDateString())
            ->with(['paciente.municipio', 'historia:id,respuestas', 'cups:id,codigo,nombre', 'contrato:id,numero,modalidad_contratacion_id,fecha_inicio,fecha_fin,valor', 'contrato.modalidad:id,codigo'])
            ->get()
            ->map(fn (OrdenQuirurgica $o) => ['orden' => $o] + $this->prioridad->calcular($o, $hoy))
            ->sortBy([['puntaje', 'desc'], [fn ($a, $b) => $a['orden']->fecha_orden <=> $b['orden']->fecha_orden]])
            ->values();

        $this->carga = Cirugia::whereIn('estado', ['PROPUESTA', 'APROBADA'])->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('cirujano_id, COUNT(*) as total')->groupBy('cirujano_id')->pluck('total', 'cirujano_id')->map(fn ($n) => (int) $n)->all();

        $this->agendas = Agenda::where('activo', true)->get()->groupBy('especialista_id');
        $this->ausencias = Ausencia::whereDate('fecha_inicio', '<=', $hasta->toDateString())->whereDate('fecha_fin', '>=', $desde->toDateString())->get()->groupBy('especialista_id');
        $this->ocupacion = [];
        $this->verificaciones = [];
        $this->verificador->recordar();

        try {
            return $this->correr($ordenes, $desde, $hasta, $sedeIds, $usuarioId, $reglas, $hoy);
        } finally {
            $this->verificador->olvidar();
        }
    }

    private function correr(Collection $ordenes, Carbon $desde, Carbon $hasta, ?array $sedeIds, ?int $usuarioId, array $reglas, Carbon $hoy): Corrida
    {
        return DB::transaction(function () use ($ordenes, $desde, $hasta, $sedeIds, $usuarioId, $reglas, $hoy) {
            $corrida = Corrida::create(['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'sede_ids' => $sedeIds, 'estado' => 'PROPUESTA', 'creada_por' => $usuarioId]);
            $noProgramadas = [];
            $programadas = 0;

            foreach ($ordenes as $candidata) {
                $resultado = $this->programar($corrida, $candidata, $desde, $hasta, $sedeIds, $reglas, $hoy);
                if ($resultado === true) {
                    $programadas++;

                    continue;
                }
                $o = $candidata['orden'];
                $noProgramadas[] = [
                    'orden_id' => $o->id,
                    'paciente' => $o->paciente?->nombre_completo,
                    'cups' => $o->cups?->codigo,
                    'puntaje' => $candidata['puntaje'],
                    'motivo' => $resultado,
                    'aval_hasta' => $o->aval_hasta?->toDateString(),
                    'aval_por_vencer' => $o->aval_hasta && $hoy->diffInDays($o->aval_hasta, false) <= $reglas['pesos']['dias_alerta_aval'],
                ];
            }

            $corrida->update(['resumen' => ['evaluadas' => $ordenes->count(), 'programadas' => $programadas, 'no_programadas' => $noProgramadas]]);

            return $corrida->refresh();
        });
    }

    /**
     * Busca el primer cupo viable para la orden. Devuelve true si la programó, o el motivo por el que no pudo.
     */
    private function programar(Corrida $corrida, array $candidata, Carbon $desde, Carbon $hasta, ?array $sedeIds, array $reglas, Carbon $hoy): bool|string
    {
        /** @var OrdenQuirurgica $orden */
        $orden = $candidata['orden'];
        // La cirugía debe caer dentro del aval y de la vigencia del contrato (fuera de ella la EPS no la reconoce).
        $contrato = $orden->contrato;
        $inicioVentana = collect([$orden->programable_desde, $hoy->copy()->addDays($reglas['anticipacion_dias']), $desde, $contrato?->fecha_inicio])
            ->filter()->map(fn (Carbon $f) => $f->copy()->startOfDay())->sortBy(fn (Carbon $f) => $f->timestamp)->last();
        $finAval = collect([$orden->aval_hasta, $hasta])
            ->map(fn (Carbon $f) => $f->copy()->startOfDay())->sortBy(fn (Carbon $f) => $f->timestamp)->first();
        $finVentana = $contrato?->fecha_fin && $contrato->fecha_fin->copy()->startOfDay()->lt($finAval) ? $contrato->fecha_fin->copy()->startOfDay() : $finAval;
        if ($inicioVentana->gt($finVentana)) {
            if ($contrato?->fecha_fin && $contrato->fecha_fin->lt($inicioVentana)) {
                return "El contrato {$contrato->numero} vence el {$contrato->fecha_fin->format('d/m/Y')}, antes de la primera fecha posible.";
            }
            if ($contrato?->fecha_inicio && $contrato->fecha_inicio->gt($finAval)) {
                return "El contrato {$contrato->numero} empieza el {$contrato->fecha_inicio->format('d/m/Y')}, después del periodo o del aval.";
            }

            return 'La ventana del aval no alcanza el periodo programado.';
        }

        $sedes = $this->sedesCandidatas($orden, $sedeIds);
        if ($sedes->isEmpty()) {
            return 'Ninguna sede del contrato tiene este CUPS en su portafolio.';
        }

        $cirujanos = $orden->cirujano_id
            ? Especialista::whereKey($orden->cirujano_id)->where('activo', true)->get()
            : Especialista::where('activo', true)->whereHas('especialidades', fn ($q) => $q->whereKey($orden->especialidad_id))->get();
        if ($cirujanos->isEmpty()) {
            return $orden->cirujano_id ? 'El cirujano indicado en la orden no está activo.' : 'No hay cirujanos activos de la especialidad.';
        }

        // El motivo más avanzado al que se llegó, para explicar por qué no se programó.
        $motivo = 'Los cirujanos no tienen agenda en la ventana del aval.';
        $nivelMotivo = 0;
        $anotar = function (int $nivel, string $texto) use (&$motivo, &$nivelMotivo) {
            if ($nivel >= $nivelMotivo) {
                $nivelMotivo = $nivel;
                $motivo = $texto;
            }
        };

        foreach (CarbonPeriod::create($inicioVentana, $finVentana) as $dia) {
            foreach ($this->opcionesDelDia($sedes, $cirujanos, $orden->especialidad_id, $dia, $reglas, $candidata['temprano']) as [$sede, $cirujano, $horaInicio, $horaFin]) {
                $anotar(1, 'Los cirujanos no tienen horario libre en la ventana del aval.');
                $inicio = Carbon::parse("{$dia->toDateString()} {$horaInicio}");
                $fin = Carbon::parse("{$dia->toDateString()} {$horaFin}");

                $verificacion = $this->verificar($orden->cups_id, $sede->id, $inicio, $fin->copy()->addMinutes($reglas['rotacion_minutos']));
                if (! $verificacion['viable']) {
                    $faltante = collect($verificacion['recursos'])->firstWhere('ok', false);
                    $anotar(2, $verificacion['motivos'][0] ?? ($faltante ? "Falta {$faltante['item']['nombre']} ({$faltante['disponible']} de {$faltante['requerido']})." : 'Faltan recursos.'));

                    continue;
                }

                $anestesiologo = $this->anestesiologo($verificacion['sala']['id'] ?? null, $sede->id, $inicio, $fin, $reglas, $corrida);
                if ($anestesiologo === false) {
                    $anotar(3, 'No hay anestesiólogo disponible para la sala en esa jornada.');

                    continue;
                }

                $this->crearCirugia($corrida, $candidata, $sede->id, $cirujano->id, $anestesiologo, $inicio, $fin, $verificacion, $reglas);

                return true;
            }
        }

        return $motivo;
    }

    /**
     * Todas las combinaciones sede × cirujano × hora de un día, de la mejor a la peor:
     * primero la hora (respetando la franja temprana reservada), luego la sede más cercana
     * y por último el cirujano con menos carga.
     *
     * @return list<array{0: object, 1: Especialista, 2: string, 3: string}>
     */
    private function opcionesDelDia(Collection $sedes, Collection $cirujanos, int $especialidadId, Carbon $dia, array $reglas, bool $temprano): array
    {
        $reservada = $reglas['hora_reservada_temprana'];
        $opciones = [];
        foreach ($sedes->values() as $cercania => $sede) {
            foreach ($cirujanos as $cirujano) {
                foreach ($this->horasDelCirujano($cirujano, $especialidadId, $sede->id, $dia, (int) $sede->duracion_minutos, $reglas) as [$inicio, $fin]) {
                    $opciones[] = [
                        'clave' => [! $temprano && $inicio < $reservada ? 1 : 0, $inicio, $cercania, $this->carga[$cirujano->id] ?? 0],
                        'opcion' => [$sede, $cirujano, $inicio, $fin],
                    ];
                }
            }
        }
        usort($opciones, fn ($a, $b) => $a['clave'] <=> $b['clave']);

        return array_column($opciones, 'opcion');
    }

    /**
     * Sedes del contrato que ofrecen el CUPS, primero las del municipio y departamento del paciente.
     *
     * @return Collection<int, object{id: int, duracion_minutos: int}>
     */
    private function sedesCandidatas(OrdenQuirurgica $orden, ?array $sedeIds): Collection
    {
        $contrato = $orden->contrato_id ? DB::table('contrato_sede')->where('contrato_id', $orden->contrato_id)->pluck('sede_id')->all() : [];
        $municipio = $orden->paciente?->municipio;

        return PortafolioItem::query()
            ->join('sedes', 'sedes.id', '=', 'portafolio_servicios.sede_id')
            ->join('municipios', 'municipios.id', '=', 'sedes.municipio_id')
            ->where('portafolio_servicios.cups_id', $orden->cups_id)->where('portafolio_servicios.activo', true)
            ->where('sedes.activo', true)->whereNull('sedes.deleted_at')
            ->when($contrato, fn ($q) => $q->whereIn('sedes.id', $contrato))
            ->when($sedeIds, fn ($q) => $q->whereIn('sedes.id', $sedeIds))
            ->get(['sedes.id', 'sedes.municipio_id', 'municipios.departamento_id', 'portafolio_servicios.duracion_minutos'])
            ->sortBy(fn ($s) => match (true) {
                $municipio && $s->municipio_id === $municipio->id => 0,
                $municipio && $s->departamento_id === $municipio->departamento_id => 1,
                default => 2,
            })
            ->values();
    }

    /**
     * Horas de inicio posibles del cirujano ese día en esa sede, libres de citas y otras cirugías.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function horasDelCirujano(Especialista $cirujano, int $especialidadId, int $sedeId, Carbon $dia, int $duracion, array $reglas): array
    {
        $fecha = $dia->toDateString();
        if ($this->ausente($cirujano->id, $fecha)) {
            return [];
        }

        $franjas = $this->agendasDelDia($cirujano->id, $sedeId, $dia, $especialidadId);
        if ($franjas->isEmpty()) {
            return [];
        }

        $ocupado = $this->ocupado($cirujano->id, $fecha);

        $horas = [];
        foreach ($franjas as $franja) {
            for ($m = $this->minutos($franja->hora_inicio); $m + $duracion <= $this->minutos($franja->hora_fin); $m += $reglas['paso_minutos']) {
                [$i, $f] = [$this->hora($m), $this->hora($m + $duracion)];
                if ($ocupado->every(fn ($o) => ! ($i < $o->hora_fin && $o->hora_inicio < $f))) {
                    $horas[] = [$i, $f];
                }
            }
        }

        return $horas;
    }

    /**
     * Anestesiólogo de la sala en la jornada. Si la sala aún no tiene, se asigna uno libre.
     * Devuelve el id, null si el procedimiento no usa sala, o false si no hay disponible.
     */
    private function anestesiologo(?int $salaId, int $sedeId, Carbon $inicio, Carbon $fin, array $reglas, Corrida $corrida): int|false|null
    {
        if (! $salaId) {
            return null;
        }

        $fecha = $inicio->toDateString();
        $jornada = $this->jornada($inicio->format('H:i'), $reglas);
        $asignado = AsignacionAnestesia::where('sala_id', $salaId)->whereDate('fecha', $fecha)->where('jornada', $jornada)->value('especialista_id');
        if ($asignado) {
            return $this->anestesiologoCubre($asignado, $sedeId, $inicio, $fin) ? $asignado : false;
        }

        $especialidad = $this->reglasPreanestesia->especialidad();
        if (! $especialidad) {
            return false;
        }
        $ocupados = AsignacionAnestesia::whereDate('fecha', $fecha)->where('jornada', $jornada)->pluck('especialista_id')->all();
        $candidatos = Especialista::where('activo', true)->whereNotIn('id', $ocupados)
            ->whereHas('especialidades', fn ($q) => $q->whereKey($especialidad->id))
            ->withCount(['asignacionesAnestesia as asignaciones_count'])
            ->orderBy('asignaciones_count')->get();

        foreach ($candidatos as $c) {
            if ($this->anestesiologoCubre($c->id, $sedeId, $inicio, $fin)) {
                AsignacionAnestesia::create(['sala_id' => $salaId, 'especialista_id' => $c->id, 'corrida_id' => $corrida->id, 'fecha' => $fecha, 'jornada' => $jornada]);

                return $c->id;
            }
        }

        return false;
    }

    /**
     * El anestesiólogo tiene agenda de anestesiología en la sede que cubre la cirugía, no está ausente ni con citas.
     */
    private function anestesiologoCubre(int $especialistaId, int $sedeId, Carbon $inicio, Carbon $fin): bool
    {
        $fecha = $inicio->toDateString();
        [$i, $f] = [$inicio->format('H:i'), $fin->format('H:i')];
        $especialidad = $this->reglasPreanestesia->especialidad();

        $cubre = $this->agendasDelDia($especialistaId, $sedeId, $inicio, $especialidad?->id)
            ->contains(fn (Agenda $a) => $a->hora_inicio <= $i && $a->hora_fin >= $f);

        return $cubre
            && ! $this->ausente($especialistaId, $fecha)
            && ! $this->ocupado($especialistaId, $fecha, soloCitas: true)->contains(fn ($o) => $o->hora_inicio < $f && $o->hora_fin > $i);
    }

    /**
     * Franjas de agenda activas y vigentes del especialista en la sede para ese día de la semana.
     *
     * @return Collection<int, Agenda>
     */
    private function agendasDelDia(int $especialistaId, int $sedeId, Carbon $dia, ?int $especialidadId): Collection
    {
        $fecha = $dia->toDateString();

        return ($this->agendas[$especialistaId] ?? collect())->filter(fn (Agenda $a) => $a->sede_id === $sedeId
            && ($especialidadId === null || $a->especialidad_id === $especialidadId)
            && $a->vigente_desde->toDateString() <= $fecha
            && ($a->vigente_hasta === null || $a->vigente_hasta->toDateString() >= $fecha)
            && in_array($dia->isoWeekday(), $a->dias ?? [], true));
    }

    private function ausente(int $especialistaId, string $fecha): bool
    {
        return ($this->ausencias[$especialistaId] ?? collect())
            ->contains(fn (Ausencia $a) => $a->fecha_inicio->toDateString() <= $fecha && $a->fecha_fin->toDateString() >= $fecha);
    }

    /**
     * Franjas ocupadas del especialista ese día: cirugías propuestas o aprobadas (como cirujano) y citas programadas.
     */
    private function ocupado(int $especialistaId, string $fecha, bool $soloCitas = false): Collection
    {
        $clave = "{$especialistaId}|{$fecha}";
        $this->ocupacion[$clave] ??= [
            'cirugias' => Cirugia::where('cirujano_id', $especialistaId)->whereDate('fecha', $fecha)->whereIn('estado', ['PROPUESTA', 'APROBADA'])->get(['hora_inicio', 'hora_fin']),
            'citas' => Cita::where('especialista_id', $especialistaId)->whereDate('fecha', $fecha)->where('estado', 'PROGRAMADA')->get(['hora_inicio', 'hora_fin']),
        ];

        return $soloCitas ? $this->ocupacion[$clave]['citas'] : $this->ocupacion[$clave]['cirugias']->concat($this->ocupacion[$clave]['citas']);
    }

    /**
     * VerificadorRecursos con caché: el resultado solo cambia cuando se reserva algo (ver crearCirugia).
     */
    private function verificar(int $cupsId, int $sedeId, Carbon $inicio, Carbon $fin): array
    {
        return $this->verificaciones["{$cupsId}|{$sedeId}|{$inicio->toDateTimeString()}|{$fin->toDateTimeString()}"]
            ??= $this->verificador->verificar($cupsId, $sedeId, $inicio, $fin);
    }

    private function crearCirugia(Corrida $corrida, array $candidata, int $sedeId, int $cirujanoId, ?int $anestesiologoId, Carbon $inicio, Carbon $fin, array $verificacion, array $reglas): Cirugia
    {
        $orden = $candidata['orden'];
        $avisos = $verificacion['avisos'];
        if ($verificacion['sin_requerimientos']) {
            $avisos[] = 'El CUPS no tiene requerimientos de inventario definidos: no se reservaron equipos ni insumos.';
        }
        if ($candidata['temprano'] && $inicio->format('H:i') >= $reglas['hora_reservada_temprana']) {
            $avisos[] = "{$candidata['motivo_temprano']}: no hubo cupo en la primera hora del día.";
        }

        $cirugia = Cirugia::create([
            'corrida_id' => $corrida->id,
            'orden_id' => $orden->id,
            'paciente_id' => $orden->paciente_id,
            'cups_id' => $orden->cups_id,
            'sede_id' => $sedeId,
            'sala_id' => $verificacion['sala']['id'] ?? null,
            'cirujano_id' => $cirujanoId,
            'anestesiologo_id' => $anestesiologoId,
            'fecha' => $inicio->toDateString(),
            'hora_inicio' => $inicio->format('H:i'),
            'hora_fin' => $fin->format('H:i'),
            'estado' => 'PROPUESTA',
            'puntaje' => $candidata['puntaje'],
            'prioridad' => ['factores' => $candidata['factores'], 'temprano' => $candidata['motivo_temprano']],
            'avisos' => $avisos,
        ]);

        // Reservas: la sala y los equipos hasta el fin + rotación; las cajas hasta terminar la esterilización.
        $base = ['sede_id' => $sedeId, 'inicio' => $inicio, 'fin' => $fin, 'estado' => 'ACTIVA', 'referencia_tipo' => 'cirugia', 'referencia_id' => $cirugia->id];
        $conRotacion = $fin->copy()->addMinutes($reglas['rotacion_minutos']);
        if ($cirugia->sala_id) {
            Reserva::create($base + ['sala_id' => $cirugia->sala_id, 'cantidad' => 1, 'libera_en' => $conRotacion]);
        }
        $esterilizacion = Item::whereIn('id', collect($verificacion['recursos'])->pluck('item.id'))->pluck('minutos_esterilizacion', 'id');
        foreach ($verificacion['recursos'] as $r) {
            if ($r['item']['tipo'] === 'INSUMO') {
                Reserva::create($base + ['item_id' => $r['item']['id'], 'cantidad' => $r['requerido'], 'libera_en' => $fin]);

                continue;
            }
            $libera = $r['item']['tipo'] === 'INSTRUMENTAL' ? $fin->copy()->addMinutes((int) ($esterilizacion[$r['item']['id']] ?? 0)) : $conRotacion;
            foreach ($r['asignables'] as $unidad) {
                Reserva::create($base + ['item_id' => $r['item']['id'], 'unidad_id' => $unidad['id'], 'cantidad' => 1, 'libera_en' => $libera]);
            }
        }

        $this->carga[$cirujanoId] = ($this->carga[$cirujanoId] ?? 0) + 1;
        // Hay reservas nuevas: las verificaciones guardadas ya no valen, y el cirujano queda ocupado en esa franja.
        $this->verificaciones = [];
        $this->verificador->olvidarReservas();
        $this->ocupado($cirujanoId, $cirugia->fecha->toDateString());
        $this->ocupacion["{$cirujanoId}|{$cirugia->fecha->toDateString()}"]['cirugias']->push($cirugia);

        return $cirugia;
    }

    private function jornada(string $hora, array $reglas): string
    {
        foreach ($reglas['jornadas'] as $nombre => [$desde, $hasta]) {
            if ($hora >= $desde && $hora < $hasta) {
                return $nombre;
            }
        }

        return array_key_last($reglas['jornadas']);
    }

    private function minutos(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora));

        return $h * 60 + $m;
    }

    private function hora(int $minutos): string
    {
        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }
}
