<?php

namespace App\Modules\Inventario\Services;

use App\Modules\Inventario\Models\Existencia;
use App\Modules\Inventario\Models\Item;
use App\Modules\Inventario\Models\Movimiento;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Existencias de insumos por sede y lote, y sus movimientos.
 */
class ExistenciaServicio
{
    private const DIAS_POR_VENCER = 60;

    /**
     * Insumos con su total, lotes por sede y alertas (bajo mínimo, por vencer, vencidos).
     */
    public function listar(array $filtros = [], int $porPagina = 25): LengthAwarePaginator
    {
        $sedeId = ! empty($filtros['sede_id']) ? (int) $filtros['sede_id'] : null;
        $hoy = now()->toDateString();
        $limite = now()->addDays(self::DIAS_POR_VENCER)->toDateString();

        $query = Item::where('tipo', 'INSUMO')->where('activo', true)
            ->with(['existencias' => fn ($q) => $q->where('cantidad', '>', 0)->when($sedeId, fn ($e) => $e->where('sede_id', $sedeId))->with('sede:id,nombre')->orderByRaw('vence IS NULL')->orderBy('vence')]);

        if (! empty($filtros['buscar'])) {
            $termino = '%'.mb_strtolower(trim($filtros['buscar'])).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(nombre) LIKE ?', [$termino])->orWhereRaw('LOWER(codigo) LIKE ?', [$termino]));
        }
        if (! empty($filtros['por_vencer'])) {
            $query->whereHas('existencias', fn ($q) => $q->where('cantidad', '>', 0)->whereDate('vence', '<=', $limite)->when($sedeId, fn ($e) => $e->where('sede_id', $sedeId)));
        }

        $items = $query->orderBy('nombre')->get()->map(function (Item $item) use ($hoy, $limite) {
            $porSede = $item->existencias->groupBy('sede_id')->map(fn ($lotes) => [
                'sede' => $lotes->first()->sede?->only(['id', 'nombre']),
                'total' => (int) $lotes->filter(fn ($l) => ! $l->vence || $l->vence->toDateString() >= $hoy)->sum('cantidad'),
                'vencidos' => (int) $lotes->filter(fn ($l) => $l->vence && $l->vence->toDateString() < $hoy)->sum('cantidad'),
                'lotes' => $lotes->map(fn ($l) => $l->only(['id', 'lote', 'vence', 'cantidad']))->values(),
            ])->values();

            $item->setRelation('existencias', new Collection);
            $item->setAttribute('sedes', $porSede);
            $item->setAttribute('total', $porSede->sum('total'));
            $item->setAttribute('bajo_minimo', $item->stock_minimo !== null && $porSede->contains(fn ($s) => $s['total'] < $item->stock_minimo) || ($item->stock_minimo && $porSede->isEmpty()));
            $item->setAttribute('por_vencer', $porSede->flatMap(fn ($s) => $s['lotes'])->contains(fn ($l) => $l['vence'] && $l['vence']->toDateString() >= $hoy && $l['vence']->toDateString() <= $limite));
            $item->setAttribute('con_vencidos', $porSede->sum('vencidos') > 0);

            return $item;
        });

        if (! empty($filtros['bajo_minimo'])) {
            $items = $items->filter(fn ($i) => $i->bajo_minimo)->values();
        }

        $pagina = max(1, (int) ($filtros['page'] ?? 1));
        $porPagina = max(1, min($porPagina, 100));

        return new LengthAwarePaginator($items->forPage($pagina, $porPagina)->values(), $items->count(), $porPagina, $pagina);
    }

    /**
     * Entrada, salida o ajuste de un insumo en una sede.
     * - ENTRADA: suma al lote (lo crea si no existe).
     * - SALIDA / CONSUMO: descuenta del lote indicado o, sin lote, de los que vencen primero.
     * - AJUSTE: fija la cantidad del lote (conteo físico).
     *
     * @throws ValidationException
     */
    public function registrar(array $datos, ?int $usuarioId, string $origen = 'MANUAL'): array
    {
        $item = Item::find($datos['item_id']);
        if (! $item || $item->tipo !== 'INSUMO') {
            throw ValidationException::withMessages(['item_id' => ['Elige un insumo.']]);
        }

        return DB::transaction(function () use ($item, $datos, $usuarioId, $origen) {
            $sedeId = (int) $datos['sede_id'];
            $lote = trim((string) ($datos['lote'] ?? ''));
            $cantidad = (int) $datos['cantidad'];
            $motivo = $datos['motivo'] ?? null;
            $movimientos = [];

            if ($datos['tipo'] === 'ENTRADA' || $datos['tipo'] === 'AJUSTE') {
                $existencia = Existencia::lockForUpdate()->firstOrNew(['item_id' => $item->id, 'sede_id' => $sedeId, 'lote' => $lote]);
                $anterior = (int) $existencia->cantidad;
                $existencia->cantidad = $datos['tipo'] === 'ENTRADA' ? $anterior + $cantidad : $cantidad;
                if (array_key_exists('vence', $datos)) {
                    $existencia->vence = $datos['vence'];
                }
                $existencia->save();
                $movimientos[] = $this->movimiento($existencia, $datos['tipo'], $existencia->cantidad - $anterior, $motivo, $usuarioId, $origen);
            } else {
                $lotes = Existencia::lockForUpdate()->where('item_id', $item->id)->where('sede_id', $sedeId)->where('cantidad', '>', 0)
                    ->when($lote !== '', fn ($q) => $q->where('lote', $lote))
                    ->orderByRaw('vence IS NULL')->orderBy('vence')->get();
                if ($lotes->sum('cantidad') < $cantidad) {
                    throw ValidationException::withMessages(['cantidad' => ["Solo hay {$lotes->sum('cantidad')} {$item->unidad_medida} disponibles".($lote !== '' ? " en el lote {$lote}" : '').'.']]);
                }
                $pendiente = $cantidad;
                foreach ($lotes as $existencia) {
                    $sale = min($pendiente, $existencia->cantidad);
                    $existencia->decrement('cantidad', $sale);
                    $movimientos[] = $this->movimiento($existencia, $datos['tipo'], -$sale, $motivo, $usuarioId, $origen);
                    $pendiente -= $sale;
                    if ($pendiente === 0) {
                        break;
                    }
                }
            }

            return ['movimientos' => $movimientos, 'total_sede' => (int) Existencia::where('item_id', $item->id)->where('sede_id', $sedeId)->sum('cantidad')];
        });
    }

    public function movimientos(int $itemId, ?int $sedeId = null): Collection
    {
        return Movimiento::with(['existencia:id,lote', 'usuario:id,name'])->where('item_id', $itemId)
            ->when($sedeId, fn ($q) => $q->where('sede_id', $sedeId))
            ->latest('id')->limit(50)->get();
    }

    private function movimiento(Existencia $e, string $tipo, int $cantidad, ?string $motivo, ?int $usuarioId, string $origen): Movimiento
    {
        return Movimiento::create([
            'item_id' => $e->item_id, 'sede_id' => $e->sede_id, 'existencia_id' => $e->id, 'tipo' => $tipo,
            'cantidad' => $cantidad, 'saldo' => $e->cantidad, 'motivo' => $motivo, 'origen' => $origen, 'usuario_id' => $usuarioId,
        ]);
    }

    public function existencia(int $id): Existencia
    {
        return Existencia::find($id) ?? throw new ModelNotFoundException("No se encontró la existencia con ID: {$id}");
    }
}
