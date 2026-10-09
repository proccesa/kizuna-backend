<?php

namespace App\Modules\Inventario\Services;

use App\Modules\Inventario\Models\Existencia;
use App\Modules\Inventario\Models\Item;
use App\Modules\Inventario\Models\RequerimientoCups;
use App\Modules\Inventario\Models\Unidad;
use App\Modules\Servicios\Models\PortafolioItem;
use Illuminate\Support\Facades\DB;

/**
 * Cifras y alertas del inventario para las pantallas y el tablero.
 */
class AlertasInventario
{
    public function resumen(): array
    {
        $hoy = now()->toDateString();
        $en30 = now()->addDays(30)->toDateString();
        $en60 = now()->addDays(60)->toDateString();
        $equipos = Unidad::whereHas('item', fn ($q) => $q->where('tipo', 'EQUIPO'))->where('estado', '!=', 'BAJA');
        $cajas = Unidad::whereHas('item', fn ($q) => $q->where('tipo', 'INSTRUMENTAL'))->where('estado', '!=', 'BAJA');

        // Insumos con alguna sede por debajo del mínimo (o sin existencias en ninguna).
        $minimos = Item::where('tipo', 'INSUMO')->where('activo', true)->whereNotNull('stock_minimo')->get(['id', 'stock_minimo']);
        $totales = Existencia::query()->whereIn('item_id', $minimos->pluck('id'))
            ->where(fn ($q) => $q->whereNull('vence')->orWhereDate('vence', '>=', $hoy))
            ->groupBy('item_id', 'sede_id')->select('item_id', 'sede_id', DB::raw('SUM(cantidad) as total'))->get()->groupBy('item_id');
        $bajoMinimo = $minimos->filter(fn ($i) => ! $totales->has($i->id) || $totales[$i->id]->contains(fn ($t) => (int) $t->total < $i->stock_minimo))->count();

        $cupsPortafolio = PortafolioItem::where('activo', true)->distinct()->pluck('cups_id');

        return [
            'equipos' => [
                'total' => (clone $equipos)->count(),
                'operativos' => (clone $equipos)->where('estado', 'OPERATIVO')->count(),
                'fuera_servicio' => (clone $equipos)->whereIn('estado', ['MANTENIMIENTO', 'FUERA_SERVICIO'])->count(),
                'mantenimiento_vencido' => (clone $equipos)->whereDate('proximo_mantenimiento', '<', $hoy)->count(),
                'mantenimiento_proximo' => (clone $equipos)->whereDate('proximo_mantenimiento', '>=', $hoy)->whereDate('proximo_mantenimiento', '<=', $en30)->count(),
                'calibracion_vencida' => (clone $equipos)->whereDate('calibracion_vence', '<', $hoy)->count(),
                'calibracion_proxima' => (clone $equipos)->whereDate('calibracion_vence', '>=', $hoy)->whereDate('calibracion_vence', '<=', $en30)->count(),
            ],
            'instrumental' => [
                'total' => (clone $cajas)->count(),
                'operativas' => (clone $cajas)->where('estado', 'OPERATIVO')->count(),
            ],
            'insumos' => [
                'total' => Item::where('tipo', 'INSUMO')->where('activo', true)->count(),
                'bajo_minimo' => $bajoMinimo,
                'por_vencer' => Existencia::where('cantidad', '>', 0)->whereDate('vence', '>=', $hoy)->whereDate('vence', '<=', $en60)->distinct()->count('item_id'),
                'vencidos' => Existencia::where('cantidad', '>', 0)->whereDate('vence', '<', $hoy)->distinct()->count('item_id'),
            ],
            'cups' => [
                'en_portafolio' => $cupsPortafolio->count(),
                'sin_requerimientos' => $cupsPortafolio->diff(RequerimientoCups::distinct()->pluck('cups_id'))->count(),
            ],
        ];
    }
}
