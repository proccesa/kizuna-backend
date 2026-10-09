<?php

namespace App\Modules\Inventario\Services;

use App\Modules\Inventario\Models\Existencia;
use App\Modules\Inventario\Models\Item;
use App\Modules\Inventario\Models\Mantenimiento;
use App\Modules\Inventario\Models\RequerimientoCups;
use App\Modules\Inventario\Models\Reserva;
use App\Modules\Inventario\Models\Unidad;
use App\Modules\Red\Models\Sala;
use App\Modules\Servicios\Models\PortafolioItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * ¿Se puede realizar este CUPS en esta sede entre $inicio y $fin?
 *
 * Revisa la sala (si el CUPS la requiere), los equipos biomédicos (operativos, sin mantenimiento
 * en esa franja, con calibración vigente, sin reserva; el mantenimiento preventivo vencido solo avisa), las cajas de instrumental
 * (sin reserva, contando el tiempo de esterilización) y los insumos (existencias no vencidas menos
 * reservas). Los equipos fijos de una sala solo sirven si el procedimiento se hace en esa sala.
 *
 * Es la verificación que usa el motor de programación antes de proponer un cupo.
 */
class VerificadorRecursos
{
    public function __construct(
        private readonly RequerimientoServicio $requerimientos
    ) {}

    /**
     * @return array{viable: bool, sala: ?array, tipo_sala: ?string, recursos: list<array>, sin_requerimientos: bool, motivos: list<string>, avisos: list<string>}
     */
    public function verificar(int $cupsId, int $sedeId, Carbon $inicio, Carbon $fin): array
    {
        $requeridos = $this->requerimientos->efectivos($cupsId, $sedeId);
        $tipoSala = PortafolioItem::where('cups_id', $cupsId)->where('sede_id', $sedeId)->value('tipo_sala');
        $desde = $inicio->toDateTimeString();
        $hasta = $fin->toDateTimeString();

        // Unidades candidatas por ítem (equipos e instrumental), con el motivo si no sirven.
        $porUnidad = $requeridos->filter(fn (RequerimientoCups $r) => $r->item->esPorUnidad());
        $candidatas = [];
        foreach ($porUnidad as $r) {
            $candidatas[$r->item_id] = $this->unidadesDisponibles($r->item, $sedeId, $inicio, $fin);
        }

        // Salas posibles: si el CUPS requiere sala, se prueban las libres de ese tipo; si no, "sin sala".
        $salas = collect([null]);
        $motivos = [];
        if ($tipoSala) {
            $ocupadas = Reserva::queCruzan($desde, $hasta)->whereNotNull('sala_id')->pluck('sala_id');
            $salas = Sala::where('sede_id', $sedeId)->where('tipo', $tipoSala)->where('activo', true)->whereNotIn('id', $ocupadas)->orderBy('codigo')->get();
            if ($salas->isEmpty()) {
                $motivos[] = Sala::where('sede_id', $sedeId)->where('tipo', $tipoSala)->where('activo', true)->exists()
                    ? 'Todas las salas de tipo '.$this->nombreTipoSala($tipoSala).' están ocupadas en ese horario.'
                    : 'La sede no tiene salas de tipo '.$this->nombreTipoSala($tipoSala).'.';
            }
        }

        // Se elige la primera sala en la que alcanzan los equipos (móviles + fijos de esa sala).
        $mejor = null;
        foreach ($salas as $sala) {
            $recursos = $this->evaluarUnidades($porUnidad, $candidatas, $sala?->id);
            $completa = collect($recursos)->every(fn ($x) => $x['ok']);
            if ($completa || $mejor === null) {
                $mejor = ['sala' => $sala, 'recursos' => $recursos];
            }
            if ($completa) {
                break;
            }
        }
        $recursos = $mejor['recursos'] ?? $this->evaluarUnidades($porUnidad, $candidatas, null);

        foreach ($requeridos->filter(fn (RequerimientoCups $r) => $r->item->tipo === 'INSUMO') as $r) {
            $recursos[] = $this->evaluarInsumo($r, $sedeId, $inicio);
        }

        $viable = empty($motivos) && collect($recursos)->every(fn ($x) => $x['ok']);
        $avisos = collect($recursos)->flatMap(fn ($x) => $x['avisos'])->values()->all();

        return [
            'viable' => $viable,
            'sala' => isset($mejor['sala']) ? $mejor['sala']->only(['id', 'codigo', 'nombre']) : null,
            'tipo_sala' => $tipoSala,
            'recursos' => array_values($recursos),
            'sin_requerimientos' => $requeridos->isEmpty(),
            'motivos' => $motivos,
            'avisos' => $avisos,
        ];
    }

    /**
     * Unidades del ítem en la sede, cada una con `libre` y el motivo si no lo está.
     *
     * @return Collection<int, array{unidad: Unidad, libre: bool, motivo: ?string, aviso: ?string}>
     */
    private function unidadesDisponibles(Item $item, int $sedeId, Carbon $inicio, Carbon $fin): Collection
    {
        $unidades = Unidad::where('item_id', $item->id)->where('sede_id', $sedeId)->where('estado', '!=', 'BAJA')->orderBy('codigo')->get();
        if ($unidades->isEmpty()) {
            return collect();
        }

        $ids = $unidades->pluck('id');
        $desde = $inicio->toDateTimeString();
        $hasta = $fin->toDateTimeString();
        // Para el instrumental, una reserva ocupa la caja hasta que termina su esterilización.
        $reservadas = Reserva::queCruzan($desde, $hasta)->whereIn('unidad_id', $ids)->pluck('unidad_id')->flip();
        if ($item->tipo === 'INSTRUMENTAL' && $item->minutos_esterilizacion) {
            // Una caja reservada justo después también bloquea: debe quedar libre hasta fin + esterilización.
            $hastaConReproceso = $fin->copy()->addMinutes($item->minutos_esterilizacion)->toDateTimeString();
            $reservadas = $reservadas->union(Reserva::where('estado', 'ACTIVA')->whereIn('unidad_id', $ids)->where('inicio', '<', $hastaConReproceso)->where('libera_en', '>', $desde)->pluck('unidad_id')->flip());
        }
        $enMantenimiento = Mantenimiento::whereIn('unidad_id', $ids)->where('estado', 'PROGRAMADO')->where('inicio', '<', $hasta)->where('fin', '>', $desde)->pluck('unidad_id')->flip();
        $fecha = $inicio->copy()->startOfDay();

        return $unidades->map(function (Unidad $u) use ($item, $reservadas, $enMantenimiento, $fecha) {
            $motivo = match (true) {
                $u->estado !== 'OPERATIVO' => match ($u->estado) {
                    'MANTENIMIENTO' => 'En mantenimiento', 'FUERA_SERVICIO' => 'Fuera de servicio', default => $u->estado,
                },
                isset($enMantenimiento[$u->id]) => 'Mantenimiento programado en ese horario',
                $item->requiere_calibracion && (! $u->calibracion_vence || $u->calibracion_vence->lt($fecha)) => $u->calibracion_vence ? 'Calibración vencida' : 'Sin calibración registrada',
                isset($reservadas[$u->id]) => $item->tipo === 'INSTRUMENTAL' ? 'Reservada o en esterilización' : 'Reservado para otro procedimiento',
                default => null,
            };

            // El mantenimiento preventivo vencido no impide usar el equipo: solo genera un aviso.
            $aviso = $item->tipo === 'EQUIPO' && $u->proximo_mantenimiento && $u->proximo_mantenimiento->lt($fecha)
                ? "{$u->codigo}: mantenimiento preventivo vencido desde el {$u->proximo_mantenimiento->format('d/m/Y')}"
                : null;

            return ['unidad' => $u, 'libre' => $motivo === null, 'motivo' => $motivo, 'aviso' => $aviso];
        });
    }

    /**
     * @param  Collection<int, RequerimientoCups>  $requeridos
     * @param  array<int, Collection>  $candidatas
     * @return list<array>
     */
    private function evaluarUnidades(Collection $requeridos, array $candidatas, ?int $salaId): array
    {
        $resultado = [];
        foreach ($requeridos as $r) {
            $todas = $candidatas[$r->item_id] ?? collect();
            // Un equipo fijo de otra sala no sirve aquí.
            $aplicables = $todas->filter(fn ($c) => $c['unidad']->sala_id === null || $c['unidad']->sala_id === $salaId);
            $libres = $aplicables->filter(fn ($c) => $c['libre']);
            $noLibres = $aplicables->reject(fn ($c) => $c['libre']);
            $fijasEnOtraSala = $todas->count() - $aplicables->count();

            $motivos = $noLibres->map(fn ($c) => "{$c['unidad']->codigo}: {$c['motivo']}")->values()->all();
            if ($fijasEnOtraSala > 0) {
                $motivos[] = "{$fijasEnOtraSala} instalada(s) en otra sala";
            }
            if ($todas->isEmpty()) {
                $motivos[] = 'La sede no tiene unidades registradas';
            }

            $resultado[] = [
                'item' => $r->item->only(['id', 'tipo', 'codigo', 'nombre']),
                'requerido' => $r->cantidad,
                'disponible' => $libres->count(),
                'ok' => $libres->count() >= $r->cantidad,
                'asignables' => $libres->take($r->cantidad)->map(fn ($c) => $c['unidad']->only(['id', 'codigo']))->values()->all(),
                'motivos' => $motivos,
                // Avisos de las unidades que se usarían (no impiden programar).
                'avisos' => $libres->take($r->cantidad)->pluck('aviso')->filter()->values()->all(),
            ];
        }

        return $resultado;
    }

    private function evaluarInsumo(RequerimientoCups $r, int $sedeId, Carbon $inicio): array
    {
        $fecha = $inicio->toDateString();
        $existencias = (int) Existencia::where('item_id', $r->item_id)->where('sede_id', $sedeId)
            ->where(fn ($q) => $q->whereNull('vence')->orWhereDate('vence', '>=', $fecha))->sum('cantidad');
        $reservado = (int) Reserva::where('item_id', $r->item_id)->where('sede_id', $sedeId)->whereNull('unidad_id')->where('estado', 'ACTIVA')->sum('cantidad');
        $vencidas = (int) Existencia::where('item_id', $r->item_id)->where('sede_id', $sedeId)->whereDate('vence', '<', $fecha)->sum('cantidad');
        $disponible = max(0, $existencias - $reservado);

        $motivos = [];
        if ($reservado > 0) {
            $motivos[] = "{$reservado} reservados para otros procedimientos";
        }
        if ($vencidas > 0) {
            $motivos[] = "{$vencidas} vencidos para esa fecha";
        }

        return [
            'item' => $r->item->only(['id', 'tipo', 'codigo', 'nombre', 'unidad_medida']),
            'requerido' => $r->cantidad,
            'disponible' => $disponible,
            'ok' => $disponible >= $r->cantidad,
            'asignables' => [],
            'motivos' => $motivos,
            'avisos' => [],
        ];
    }

    private function nombreTipoSala(string $tipo): string
    {
        return ['QUIROFANO' => 'quirófano', 'SALA_PROCEDIMIENTOS' => 'sala de procedimientos', 'SALA_PARTOS' => 'sala de partos'][$tipo] ?? strtolower($tipo);
    }
}
