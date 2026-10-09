<?php

namespace App\Modules\Contratacion\Services;

use App\Modules\Contratacion\Models\Contrato;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cumplimiento de los contratos con lo que Kizuna programa y realiza.
 *
 * Solo cuentan las cirugías de órdenes del contrato con fecha dentro de su vigencia:
 * - realizadas: lo que la EPS reconoce;
 * - programadas: aprobadas aún sin realizar (avance comprometido).
 *
 * El porcentaje depende de la modalidad:
 * - PGP: realizadas ÷ cantidad pactada (se compara con el tiempo transcurrido del contrato);
 * - EVENTO: realizadas × tarifa ÷ valor del contrato;
 * - CÁPITA y otras: sin porcentaje, solo conteos.
 */
class CumplimientoContratos
{
    /** @var array<int, array<int, array{realizadas: int, programadas: int}>> conteos por contrato y CUPS */
    private array $conteos = [];

    /**
     * Resumen de cada contrato.
     *
     * @param  iterable<Contrato>  $contratos  con `modalidad` cargada
     * @return array<int, array<string, mixed>> por id de contrato
     */
    public function resumen(iterable $contratos, ?Carbon $hoy = null): array
    {
        $contratos = collect($contratos);
        $this->cargar($contratos->pluck('id')->all());
        $pactos = DB::table('contrato_cups')->whereIn('contrato_id', $contratos->pluck('id')->all() ?: [0])
            ->get(['contrato_id', 'cups_id', 'cantidad', 'tarifa'])->groupBy('contrato_id');

        return $contratos->mapWithKeys(fn (Contrato $c) => [$c->id => $this->calcular($c, $pactos->get($c->id, collect()), $hoy)])->all();
    }

    /**
     * Conteos por CUPS de un contrato: [cups_id => [realizadas, programadas]].
     *
     * @return array<int, array{realizadas: int, programadas: int}>
     */
    public function porCups(int $contratoId): array
    {
        $this->cargar([$contratoId]);

        return $this->conteos[$contratoId] ?? [];
    }

    /**
     * Rezago de un CUPS en un contrato PGP: cuánto va por debajo de lo esperado a la fecha.
     * Cuenta realizadas y programadas (lo ya comprometido no es rezago). Null si no aplica.
     *
     * @return array{esperado: float, actual: float, rezago: float}|null
     */
    public function rezagoPgp(Contrato $contrato, int $cupsId, ?Carbon $hoy = null): ?array
    {
        if ($contrato->modalidad?->codigo !== 'PGP') {
            return null;
        }
        $cantidad = (int) DB::table('contrato_cups')->where('contrato_id', $contrato->id)->where('cups_id', $cupsId)->value('cantidad');
        if ($cantidad <= 0) {
            return null;
        }

        $conteo = $this->porCups($contrato->id)[$cupsId] ?? ['realizadas' => 0, 'programadas' => 0];
        $esperado = $this->avanceTiempo($contrato, $hoy);
        $actual = min(1, ($conteo['realizadas'] + $conteo['programadas']) / $cantidad);

        return ['esperado' => round($esperado, 4), 'actual' => round($actual, 4), 'rezago' => round(max(0, $esperado - $actual), 4)];
    }

    /**
     * Fracción de la vigencia ya transcurrida (0 a 1).
     */
    public function avanceTiempo(Contrato $contrato, ?Carbon $hoy = null): float
    {
        $hoy ??= now()->startOfDay();
        $total = $contrato->fecha_inicio->diffInDays($contrato->fecha_fin) + 1;
        $transcurridos = $contrato->fecha_inicio->diffInDays($hoy, false) + 1;

        return $total > 0 ? max(0, min(1, $transcurridos / $total)) : 1;
    }

    private function calcular(Contrato $c, $pactos, ?Carbon $hoy): array
    {
        $conteos = $this->conteos[$c->id] ?? [];
        $realizadas = array_sum(array_column($conteos, 'realizadas'));
        $programadas = array_sum(array_column($conteos, 'programadas'));
        $modalidad = $c->modalidad?->codigo;
        $avance = $this->avanceTiempo($c, $hoy);

        $resultado = [
            'modalidad' => $modalidad,
            'realizadas' => $realizadas,
            'programadas' => $programadas,
            'avance_tiempo' => round($avance * 100, 1),
            'porcentaje' => null,
            'porcentaje_comprometido' => null,
            'meta' => null,
            'ejecutado' => null,
            'atrasado' => false,
        ];

        if ($modalidad === 'PGP') {
            $meta = (int) $pactos->sum('cantidad');
            if ($meta > 0) {
                $resultado['meta'] = $meta;
                $resultado['porcentaje'] = round($realizadas / $meta * 100, 1);
                $resultado['porcentaje_comprometido'] = round(($realizadas + $programadas) / $meta * 100, 1);
            }
        } elseif ($modalidad === 'EVENTO') {
            $tarifas = $pactos->pluck('tarifa', 'cups_id');
            $valor = fn (string $campo) => array_sum(array_map(fn ($cupsId) => ($conteos[$cupsId][$campo] ?? 0) * (float) ($tarifas[$cupsId] ?? 0), array_keys($conteos)));
            $resultado['ejecutado'] = round($valor('realizadas'), 2);
            $resultado['meta'] = (float) $c->valor;
            if ($c->valor > 0) {
                $resultado['porcentaje'] = round($resultado['ejecutado'] / $c->valor * 100, 1);
                $resultado['porcentaje_comprometido'] = round(($resultado['ejecutado'] + $valor('programadas')) / $c->valor * 100, 1);
            }
        }

        // Atrasado: lo comprometido va más de 10 puntos por debajo del tiempo transcurrido.
        if ($resultado['porcentaje_comprometido'] !== null) {
            $resultado['atrasado'] = $resultado['porcentaje_comprometido'] < $resultado['avance_tiempo'] - 10;
        }

        return $resultado;
    }

    /**
     * @param  list<int>  $contratoIds
     */
    private function cargar(array $contratoIds): void
    {
        $faltan = array_values(array_diff($contratoIds, array_keys($this->conteos)));
        if (! $faltan) {
            return;
        }
        foreach ($faltan as $id) {
            $this->conteos[$id] = [];
        }

        DB::table('cirugias')
            ->join('ordenes_quirurgicas', 'ordenes_quirurgicas.id', '=', 'cirugias.orden_id')
            ->join('contratos', 'contratos.id', '=', 'ordenes_quirurgicas.contrato_id')
            ->whereIn('ordenes_quirurgicas.contrato_id', $faltan)
            ->whereIn('cirugias.estado', ['REALIZADA', 'APROBADA'])
            ->whereColumn('cirugias.fecha', '>=', 'contratos.fecha_inicio')
            ->whereColumn('cirugias.fecha', '<=', 'contratos.fecha_fin')
            ->groupBy('ordenes_quirurgicas.contrato_id', 'cirugias.cups_id', 'cirugias.estado')
            ->selectRaw('ordenes_quirurgicas.contrato_id, cirugias.cups_id, cirugias.estado, COUNT(*) as total')
            ->get()
            ->each(function ($fila) {
                $actual = $this->conteos[$fila->contrato_id][$fila->cups_id] ?? ['realizadas' => 0, 'programadas' => 0];
                $actual[$fila->estado === 'REALIZADA' ? 'realizadas' : 'programadas'] += (int) $fila->total;
                $this->conteos[$fila->contrato_id][$fila->cups_id] = $actual;
            });
    }

    /**
     * Olvida los conteos en memoria (tras aprobar o realizar cirugías en la misma petición).
     */
    public function reiniciar(): void
    {
        $this->conteos = [];
    }
}
