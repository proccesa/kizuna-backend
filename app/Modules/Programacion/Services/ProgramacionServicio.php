<?php

namespace App\Modules\Programacion\Services;

use App\Modules\Cirugia\Models\OrdenQuirurgica;
use App\Modules\Inventario\Models\Reserva;
use App\Modules\Inventario\Services\ExistenciaServicio;
use App\Modules\Programacion\Models\AsignacionAnestesia;
use App\Modules\Programacion\Models\Cirugia;
use App\Modules\Programacion\Models\Corrida;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ciclo de vida del programa quirúrgico: propuesta → aprobada → realizada (o cancelada).
 */
class ProgramacionServicio
{
    private const RELACIONES = [
        'paciente:id,tipo_documento_id,numero_documento,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido,fecha_nacimiento,sexo,telefono',
        'paciente.tipoDocumento:id,codigo',
        'cups:id,codigo,nombre',
        'sede:id,nombre',
        'sala:id,codigo,nombre',
        'cirujano:id,nombres,apellidos',
        'anestesiologo:id,nombres,apellidos',
        'orden:id,prioridad,asa,concepto,aval_hasta,contrato_id,referencia_externa',
        'orden.contrato:id,numero,entidad_id',
        'orden.contrato.entidad:id,razon_social,sigla',
    ];

    public function __construct(
        private readonly PrioridadServicio $prioridad,
        private readonly ReglasProgramacion $reglas,
        private readonly ExistenciaServicio $existencias
    ) {}

    /**
     * Propuesta pendiente de aprobación (o null).
     */
    public function propuesta(): ?Corrida
    {
        $corrida = Corrida::where('estado', 'PROPUESTA')->latest('id')->with('creadaPor:id,name')->first();
        $corrida?->setRelation('cirugias', $corrida->cirugias()->with(self::RELACIONES)->where('estado', 'PROPUESTA')->orderBy('fecha')->orderBy('sala_id')->orderBy('hora_inicio')->get());

        return $corrida;
    }

    /**
     * Programa aprobado (y realizado) entre dos fechas.
     */
    public function programa(string $desde, string $hasta, ?int $sedeId = null): Collection
    {
        return Cirugia::with(self::RELACIONES)
            ->whereIn('estado', ['APROBADA', 'REALIZADA'])
            ->whereBetween('fecha', [$desde, $hasta])
            ->when($sedeId, fn ($q) => $q->where('sede_id', $sedeId))
            ->orderBy('fecha')->orderBy('sala_id')->orderBy('hora_inicio')->get();
    }

    /**
     * Cola: órdenes aptas sin cirugía, ordenadas por prioridad.
     */
    public function cola(): array
    {
        $diasAlerta = $this->reglas->todas()['pesos']['dias_alerta_aval'];
        $hoy = now()->startOfDay();

        return OrdenQuirurgica::conAvalVigente()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('cirugias')->whereColumn('cirugias.orden_id', 'ordenes_quirurgicas.id')->whereIn('cirugias.estado', ['PROPUESTA', 'APROBADA']))
            ->with(['paciente', 'paciente.tipoDocumento:id,codigo', 'cups:id,codigo,nombre', 'especialidad:id,nombre', 'historia:id,respuestas', 'contrato:id,numero,entidad_id,modalidad_contratacion_id,fecha_inicio,fecha_fin', 'contrato.modalidad:id,codigo', 'contrato.entidad:id,sigla,razon_social'])
            ->get()
            ->map(function (OrdenQuirurgica $o) use ($hoy, $diasAlerta) {
                $p = $this->prioridad->calcular($o, $hoy);
                $o->unsetRelation('historia');

                return [
                    'orden' => $o,
                    'puntaje' => $p['puntaje'],
                    'factores' => $p['factores'],
                    'temprano' => $p['motivo_temprano'],
                    'dias_aval' => (int) $hoy->diffInDays($o->aval_hasta, false),
                    'aval_por_vencer' => $hoy->diffInDays($o->aval_hasta, false) <= $diasAlerta,
                ];
            })
            ->sortByDesc('puntaje')->values()->all();
    }

    public function resumen(): array
    {
        $hoy = now()->toDateString();
        $cola = collect($this->cola());

        return [
            'por_programar' => $cola->count(),
            'aval_por_vencer' => $cola->where('aval_por_vencer', true)->count(),
            'programadas_hoy' => Cirugia::where('estado', 'APROBADA')->whereDate('fecha', $hoy)->count(),
            'programadas_7_dias' => Cirugia::where('estado', 'APROBADA')->whereBetween('fecha', [$hoy, now()->addDays(7)->toDateString()])->count(),
            'realizadas_mes' => Cirugia::where('estado', 'REALIZADA')->whereBetween('fecha', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->count(),
            'propuesta_pendiente' => Corrida::where('estado', 'PROPUESTA')->value('id'),
        ];
    }

    /**
     * Aprueba la propuesta: las cirugías quedan programadas y las órdenes pasan a PROGRAMADA.
     *
     * @throws ValidationException
     */
    public function aprobar(int $corridaId, ?int $usuarioId): Corrida
    {
        $corrida = $this->corridaPendiente($corridaId);

        DB::transaction(function () use ($corrida, $usuarioId) {
            $cirugias = $corrida->cirugias()->where('estado', 'PROPUESTA')->get();
            Cirugia::whereIn('id', $cirugias->pluck('id'))->update(['estado' => 'APROBADA']);
            OrdenQuirurgica::whereIn('id', $cirugias->pluck('orden_id'))->update(['estado' => 'PROGRAMADA', 'motivo_estado' => null]);
            $corrida->update(['estado' => 'APROBADA', 'aprobada_por' => $usuarioId, 'cerrada_en' => now()]);
        });

        return $corrida->refresh();
    }

    /**
     * Descarta la propuesta y libera todos sus recursos.
     */
    public function descartar(int $corridaId): Corrida
    {
        $corrida = $this->corridaPendiente($corridaId);

        DB::transaction(function () use ($corrida) {
            foreach ($corrida->cirugias()->where('estado', 'PROPUESTA')->get() as $cirugia) {
                $this->liberar($cirugia, 'Propuesta descartada');
            }
            $corrida->update(['estado' => 'DESCARTADA', 'cerrada_en' => now()]);
        });

        return $corrida->refresh();
    }

    /**
     * Quita una cirugía de la propuesta antes de aprobarla. La orden vuelve a la cola.
     */
    public function rechazar(int $id, string $motivo): Cirugia
    {
        $cirugia = $this->cirugia($id);
        if ($cirugia->estado !== 'PROPUESTA') {
            throw ValidationException::withMessages(['estado' => ['Solo se quitan cirugías propuestas.']]);
        }
        DB::transaction(fn () => $this->liberar($cirugia, $motivo));

        return $cirugia->refresh();
    }

    /**
     * Cancela una cirugía aprobada: libera recursos y la orden vuelve a la cola si el aval sigue vigente.
     */
    public function cancelar(int $id, string $motivo): Cirugia
    {
        $cirugia = $this->cirugia($id);
        if ($cirugia->estado !== 'APROBADA') {
            throw ValidationException::withMessages(['estado' => ['Solo se cancelan cirugías programadas.']]);
        }
        DB::transaction(function () use ($cirugia, $motivo) {
            $this->liberar($cirugia, $motivo);
            OrdenQuirurgica::whereKey($cirugia->orden_id)->update(['estado' => 'APTA', 'motivo_estado' => "Cirugía cancelada: {$motivo}"]);
        });

        return $cirugia->refresh();
    }

    /**
     * Marca la cirugía como realizada: descuenta los insumos reservados y la orden queda OPERADA.
     *
     * @return array{cirugia: Cirugia, avisos: list<string>}
     */
    public function realizar(int $id, ?int $usuarioId): array
    {
        $cirugia = $this->cirugia($id);
        if ($cirugia->estado !== 'APROBADA') {
            throw ValidationException::withMessages(['estado' => ['Solo se marcan como realizadas las cirugías programadas.']]);
        }

        $avisos = [];
        DB::transaction(function () use ($cirugia, $usuarioId, &$avisos) {
            $reservas = Reserva::where('referencia_tipo', 'cirugia')->where('referencia_id', $cirugia->id)->where('estado', 'ACTIVA')->get();
            foreach ($reservas->whereNotNull('item_id')->whereNull('unidad_id') as $insumo) {
                try {
                    $this->existencias->registrar(['item_id' => $insumo->item_id, 'sede_id' => $insumo->sede_id, 'tipo' => 'CONSUMO', 'cantidad' => $insumo->cantidad, 'motivo' => "Cirugía #{$cirugia->id}"], $usuarioId, 'MANUAL');
                } catch (ValidationException $e) {
                    $avisos[] = collect($e->errors())->flatten()->first();
                } catch (Throwable) {
                    $avisos[] = "No se pudo descontar el insumo #{$insumo->item_id}.";
                }
                $insumo->update(['estado' => 'CONSUMIDA']);
            }
            // Sala, equipos y cajas conservan su reserva hasta liberarse (rotación y esterilización).
            $cirugia->update(['estado' => 'REALIZADA']);
            OrdenQuirurgica::whereKey($cirugia->orden_id)->update(['estado' => 'OPERADA']);
        });

        return ['cirugia' => $cirugia->refresh(), 'avisos' => $avisos];
    }

    private function liberar(Cirugia $cirugia, string $motivo): void
    {
        Reserva::where('referencia_tipo', 'cirugia')->where('referencia_id', $cirugia->id)->where('estado', 'ACTIVA')->update(['estado' => 'LIBERADA']);
        $cirugia->update(['estado' => 'CANCELADA', 'motivo_cancelacion' => $motivo]);

        // Si la sala ya no tiene cirugías en esa jornada, el anestesiólogo queda libre.
        if ($cirugia->sala_id && $cirugia->anestesiologo_id) {
            $jornadas = $this->reglas->todas()['jornadas'];
            foreach (AsignacionAnestesia::where('sala_id', $cirugia->sala_id)->whereDate('fecha', $cirugia->fecha)->get() as $asignacion) {
                [$desde, $hasta] = $jornadas[$asignacion->jornada] ?? ['00:00', '24:00'];
                $quedan = Cirugia::where('sala_id', $cirugia->sala_id)->whereDate('fecha', $cirugia->fecha)->whereIn('estado', ['PROPUESTA', 'APROBADA'])
                    ->where('hora_inicio', '>=', $desde)->where('hora_inicio', '<', $hasta)->exists();
                if (! $quedan) {
                    $asignacion->delete();
                }
            }
        }
    }

    private function corridaPendiente(int $id): Corrida
    {
        $corrida = Corrida::find($id);
        if (! $corrida) {
            throw new ModelNotFoundException("No se encontró la propuesta con ID: {$id}");
        }
        if ($corrida->estado !== 'PROPUESTA') {
            throw ValidationException::withMessages(['estado' => ['La propuesta ya fue aprobada o descartada.']]);
        }

        return $corrida;
    }

    private function cirugia(int $id): Cirugia
    {
        return Cirugia::find($id) ?? throw new ModelNotFoundException("No se encontró la cirugía con ID: {$id}");
    }
}
