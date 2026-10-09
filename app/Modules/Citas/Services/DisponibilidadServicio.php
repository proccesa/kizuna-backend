<?php

namespace App\Modules\Citas\Services;

use App\Modules\Citas\Models\Cita;
use App\Modules\Programacion\Models\AsignacionAnestesia;
use App\Modules\Programacion\Services\ReglasProgramacion;
use App\Modules\Servicios\Models\PortafolioItem;
use App\Modules\Talento\Models\Agenda;
use App\Modules\Talento\Models\Ausencia;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Calcula los cupos libres de una especialidad a partir de las agendas (franjas semanales),
 * descontando las novedades de cada profesional y las citas ya asignadas.
 */
class DisponibilidadServicio
{
    /**
     * Cupos libres ordenados por fecha y hora.
     *
     * @param  list<int>|null  $sedeIds  Sedes permitidas (las del contrato); null = todas.
     * @return Collection<int, array{fecha: string, hora_inicio: string, hora_fin: string, especialista_id: int, especialista: string, sede_id: int, sede: string, agenda_id: int, consultorio: ?string}>
     */
    public function cupos(int $especialidadId, int $cupsId, Carbon $desde, Carbon $hasta, ?array $sedeIds = null, int $duracionPorDefecto = 30, int $limite = 10): Collection
    {
        $agendas = Agenda::query()
            ->where('especialidad_id', $especialidadId)
            ->where('activo', true)
            ->whereDate('vigente_desde', '<=', $hasta->toDateString())
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $desde->toDateString()))
            ->when($sedeIds !== null, fn ($q) => $q->whereIn('sede_id', $sedeIds ?: [0]))
            ->whereHas('especialista', fn ($q) => $q->where('activo', true))
            ->whereHas('sede', fn ($q) => $q->where('activo', true))
            ->with(['especialista:id,nombres,apellidos', 'sede:id,nombre'])
            ->get();

        if ($agendas->isEmpty()) {
            return collect();
        }

        $especialistas = $agendas->pluck('especialista_id')->unique()->values();
        $duraciones = PortafolioItem::whereIn('sede_id', $agendas->pluck('sede_id')->unique())->where('cups_id', $cupsId)->where('activo', true)
            ->pluck('duracion_minutos', 'sede_id');
        $ausencias = Ausencia::whereIn('especialista_id', $especialistas)
            ->whereDate('fecha_fin', '>=', $desde->toDateString())->whereDate('fecha_inicio', '<=', $hasta->toDateString())
            ->get()->groupBy('especialista_id');
        $ocupadas = Cita::whereIn('especialista_id', $especialistas)->where('estado', '!=', 'CANCELADA')
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get(['especialista_id', 'fecha', 'hora_inicio', 'hora_fin'])
            ->groupBy(fn (Cita $c) => $c->especialista_id.'|'.$c->fecha->toDateString());

        // Un anestesiólogo asignado a un quirófano en una jornada no atiende consultas en ese bloque.
        $jornadas = app(ReglasProgramacion::class)->todas()['jornadas'];
        $bloqueos = AsignacionAnestesia::whereIn('especialista_id', $especialistas)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->get()
            ->groupBy(fn ($a) => $a->especialista_id.'|'.$a->fecha->toDateString())
            ->map(fn ($lista) => $lista->map(fn ($a) => $jornadas[$a->jornada] ?? null)->filter()->values());

        $cupos = collect();
        foreach (CarbonPeriod::create($desde->copy()->startOfDay(), $hasta->copy()->startOfDay()) as $dia) {
            $fecha = $dia->toDateString();
            $delDia = $agendas->filter(fn (Agenda $a) => in_array($dia->isoWeekday(), $a->dias ?? [], true)
                && $a->vigente_desde->toDateString() <= $fecha
                && ($a->vigente_hasta === null || $a->vigente_hasta->toDateString() >= $fecha)
                && ! $this->ausente($ausencias->get($a->especialista_id), $fecha));

            $candidatos = collect();
            foreach ($delDia as $agenda) {
                $duracion = (int) ($duraciones[$agenda->sede_id] ?? $duracionPorDefecto);
                $citas = $ocupadas->get($agenda->especialista_id.'|'.$fecha, collect());
                $enQuirofano = $bloqueos->get($agenda->especialista_id.'|'.$fecha, collect());
                foreach ($this->franjas($agenda->hora_inicio, $agenda->hora_fin, $duracion) as [$inicio, $fin]) {
                    $libre = $citas->every(fn (Cita $c) => ! ($inicio < $c->hora_fin && $c->hora_inicio < $fin))
                        && $enQuirofano->every(fn ($j) => ! ($inicio < $j[1] && $j[0] < $fin));
                    if ($libre) {
                        $candidatos->push([
                            'fecha' => $fecha,
                            'hora_inicio' => $inicio,
                            'hora_fin' => $fin,
                            'especialista_id' => $agenda->especialista_id,
                            'especialista' => $agenda->especialista?->nombre_completo,
                            'sede_id' => $agenda->sede_id,
                            'sede' => $agenda->sede?->nombre,
                            'agenda_id' => $agenda->id,
                            'consultorio' => $agenda->consultorio,
                            // Para repartir la carga: menos citas ese día va primero en empates de hora
                            '_carga' => $citas->count(),
                        ]);
                    }
                }
            }

            foreach ($candidatos->sortBy([['hora_inicio', 'asc'], ['_carga', 'asc']]) as $cupo) {
                unset($cupo['_carga']);
                $cupos->push($cupo);
                if ($cupos->count() >= $limite) {
                    return $cupos;
                }
            }
        }

        return $cupos;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function franjas(string $inicio, string $fin, int $duracion): array
    {
        $resultado = [];
        $cursor = $this->minutos($inicio);
        $limite = $this->minutos($fin);
        while ($cursor + $duracion <= $limite) {
            $resultado[] = [$this->hora($cursor), $this->hora($cursor + $duracion)];
            $cursor += $duracion;
        }

        return $resultado;
    }

    private function ausente(?Collection $ausencias, string $fecha): bool
    {
        return (bool) $ausencias?->contains(fn (Ausencia $a) => $a->fecha_inicio->toDateString() <= $fecha && $a->fecha_fin->toDateString() >= $fecha);
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
