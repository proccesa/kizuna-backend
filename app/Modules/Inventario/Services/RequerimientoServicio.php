<?php

namespace App\Modules\Inventario\Services;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Inventario\Models\RequerimientoCups;
use App\Modules\Servicios\Models\PortafolioItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequerimientoServicio
{
    /**
     * CUPS del portafolio con cuántos requerimientos tienen (para la lista lateral).
     */
    public function listarCups(array $filtros = [], int $porPagina = 30): LengthAwarePaginator
    {
        $query = Cups::query()
            ->whereIn('id', PortafolioItem::select('cups_id'))
            ->select('cups.id', 'cups.codigo', 'cups.nombre')
            ->selectSub(RequerimientoCups::query()->whereColumn('cups_id', 'cups.id')->whereNull('sede_id')->selectRaw('COUNT(*)'), 'requerimientos_count')
            ->selectSub(RequerimientoCups::query()->whereColumn('cups_id', 'cups.id')->whereNotNull('sede_id')->selectRaw('COUNT(DISTINCT sede_id)'), 'sedes_ajustadas_count');

        if (! empty($filtros['buscar'])) {
            $termino = trim($filtros['buscar']);
            $query->where(fn ($q) => $q->where('codigo', 'like', "{$termino}%")->orWhere('nombre_normalizado', 'like', '%'.mb_strtoupper(Str::ascii($termino)).'%'));
        }
        if (! empty($filtros['sin_requerimientos'])) {
            $query->whereNotIn('id', RequerimientoCups::select('cups_id'));
        }

        return $query->orderBy('codigo')->paginate(max(1, min($porPagina, 100)));
    }

    /**
     * Lista base, ajustes por sede y la lista efectiva de cada sede del portafolio.
     */
    public function detalle(int $cupsId): array
    {
        $cups = Cups::find($cupsId, ['id', 'codigo', 'nombre']);
        if (! $cups) {
            throw new ModelNotFoundException("No se encontró el CUPS con ID: {$cupsId}");
        }

        $todos = RequerimientoCups::with('item:id,tipo,codigo,nombre,unidad_medida')->where('cups_id', $cupsId)->get();
        $sedes = PortafolioItem::with('sede:id,nombre,prestador_id')->where('cups_id', $cupsId)->get(['sede_id', 'tipo_sala', 'duracion_minutos']);

        return [
            'cups' => $cups,
            'base' => $todos->whereNull('sede_id')->values(),
            'sedes' => $sedes->map(fn ($p) => [
                'sede' => $p->sede?->only(['id', 'nombre']),
                'tipo_sala' => $p->tipo_sala,
                'duracion_minutos' => $p->duracion_minutos,
                'ajustes' => $todos->where('sede_id', $p->sede_id)->values(),
                'efectivos' => $this->efectivos($cupsId, $p->sede_id)->values(),
            ])->values(),
        ];
    }

    /**
     * Lista que aplica en una sede: la base, con los ajustes de la sede (cantidad 0 = se quita).
     *
     * @return Collection<int, RequerimientoCups>
     */
    public function efectivos(int $cupsId, int $sedeId): Collection
    {
        $filas = RequerimientoCups::with('item')->where('cups_id', $cupsId)
            ->where(fn ($q) => $q->whereNull('sede_id')->orWhere('sede_id', $sedeId))->get();

        // El ajuste de la sede reemplaza al ítem de la base (array_replace por item_id).
        $efectivos = array_replace(
            $filas->whereNull('sede_id')->keyBy('item_id')->all(),
            $filas->whereNotNull('sede_id')->keyBy('item_id')->all()
        );

        return collect($efectivos)->filter(fn (RequerimientoCups $r) => $r->cantidad > 0 && $r->item && $r->item->activo)->values();
    }

    /**
     * Reemplaza la lista base (sede null) o el ajuste de una sede.
     *
     * @param  list<array{item_id: int, cantidad: int, notas?: ?string}>  $items
     *
     * @throws ValidationException
     */
    public function guardar(int $cupsId, ?int $sedeId, array $items): array
    {
        if (count(array_unique(array_column($items, 'item_id'))) !== count($items)) {
            throw ValidationException::withMessages(['items' => ['Un elemento está repetido en la lista.']]);
        }
        if ($sedeId === null && collect($items)->contains(fn ($i) => (int) $i['cantidad'] === 0)) {
            throw ValidationException::withMessages(['items' => ['En la lista base la cantidad debe ser mayor que cero.']]);
        }

        DB::transaction(function () use ($cupsId, $sedeId, $items) {
            RequerimientoCups::where('cups_id', $cupsId)->when($sedeId === null, fn ($q) => $q->whereNull('sede_id'), fn ($q) => $q->where('sede_id', $sedeId))->delete();
            foreach ($items as $i) {
                RequerimientoCups::create(['cups_id' => $cupsId, 'sede_id' => $sedeId, 'item_id' => $i['item_id'], 'cantidad' => $i['cantidad'], 'notas' => $i['notas'] ?? null]);
            }
        });

        return $this->detalle($cupsId);
    }
}
