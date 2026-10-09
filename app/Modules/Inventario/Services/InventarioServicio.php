<?php

namespace App\Modules\Inventario\Services;

use App\Modules\Inventario\Models\Item;
use App\Modules\Inventario\Models\Mantenimiento;
use App\Modules\Inventario\Models\RequerimientoCups;
use App\Modules\Inventario\Models\Unidad;
use App\Modules\Red\Models\Sala;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de ítems, unidades (equipos y cajas) y sus mantenimientos.
 */
class InventarioServicio
{
    // ------------------------------------------------------------------ Ítems

    public function listarItems(array $filtros = [], int $porPagina = 50): LengthAwarePaginator
    {
        $query = Item::query()
            ->withCount(['unidades as unidades_count' => fn ($q) => $q->where('estado', '!=', 'BAJA')])
            ->withCount(['unidades as operativas_count' => fn ($q) => $q->where('estado', 'OPERATIVO')])
            ->withSum('existencias as existencias_total', 'cantidad');

        if (! empty($filtros['tipo'])) {
            $query->where('tipo', strtoupper($filtros['tipo']));
        }
        if (isset($filtros['activo']) && $filtros['activo'] !== '') {
            $query->where('activo', filter_var($filtros['activo'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filtros['buscar'])) {
            $termino = '%'.mb_strtolower(trim($filtros['buscar'])).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(nombre) LIKE ?', [$termino])->orWhereRaw('LOWER(codigo) LIKE ?', [$termino]));
        }

        return $query->orderBy('nombre')->paginate(max(1, min($porPagina, 200)));
    }

    public function crearItem(array $datos): Item
    {
        return Item::create($datos + ['activo' => true])->refresh();
    }

    public function actualizarItem(int $id, array $datos): Item
    {
        $item = $this->item($id);
        if (isset($datos['tipo']) && $datos['tipo'] !== $item->tipo && ($item->unidades()->exists() || $item->existencias()->exists())) {
            throw ValidationException::withMessages(['tipo' => ['No se puede cambiar el tipo de un ítem que ya tiene inventario.']]);
        }
        $item->update($datos);

        return $item->refresh();
    }

    /**
     * @throws ValidationException si el ítem tiene inventario o está en requerimientos de CUPS.
     */
    public function eliminarItem(int $id): bool
    {
        $item = $this->item($id);
        if ($item->unidades()->exists() || $item->existencias()->where('cantidad', '>', 0)->exists()) {
            throw ValidationException::withMessages(['item' => ['El ítem tiene inventario registrado. Desactívalo en lugar de eliminarlo.']]);
        }
        if (RequerimientoCups::where('item_id', $id)->exists()) {
            throw ValidationException::withMessages(['item' => ['El ítem está en los requerimientos de algún CUPS. Quítalo de allí primero.']]);
        }

        return (bool) $item->delete();
    }

    // ------------------------------------------------------------------ Unidades

    public function listarUnidades(array $filtros = [], int $porPagina = 25): LengthAwarePaginator
    {
        $query = Unidad::with(['item:id,tipo,codigo,nombre,requiere_calibracion,minutos_esterilizacion', 'sede:id,nombre', 'sala:id,codigo,nombre']);

        if (! empty($filtros['tipo'])) {
            $query->whereHas('item', fn ($q) => $q->where('tipo', strtoupper($filtros['tipo'])));
        }
        foreach (['item_id', 'sede_id', 'sala_id'] as $campo) {
            if (! empty($filtros[$campo])) {
                $query->where($campo, (int) $filtros[$campo]);
            }
        }
        if (! empty($filtros['estado'])) {
            $query->where('estado', strtoupper($filtros['estado']));
        } else {
            $query->where('estado', '!=', 'BAJA');
        }

        $hoy = now()->toDateString();
        $limite = now()->addDays(30)->toDateString();
        match ($filtros['alerta'] ?? null) {
            'mantenimiento' => $query->whereDate('proximo_mantenimiento', '<=', $limite),
            'calibracion' => $query->whereDate('calibracion_vence', '<=', $limite),
            'vencidos' => $query->where(fn ($q) => $q->whereDate('proximo_mantenimiento', '<', $hoy)->orWhereDate('calibracion_vence', '<', $hoy)),
            default => null,
        };

        if (! empty($filtros['buscar'])) {
            $termino = '%'.mb_strtolower(trim($filtros['buscar'])).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(codigo) LIKE ?', [$termino])->orWhereRaw('LOWER(serie) LIKE ?', [$termino])
                ->orWhereRaw('LOWER(marca) LIKE ?', [$termino])->orWhereRaw('LOWER(modelo) LIKE ?', [$termino])
                ->orWhereHas('item', fn ($i) => $i->whereRaw('LOWER(nombre) LIKE ?', [$termino])));
        }

        return $query->orderBy('sede_id')->orderBy('codigo')->paginate(max(1, min($porPagina, 100)));
    }

    public function obtenerUnidad(int $id): Unidad
    {
        $unidad = Unidad::with([
            'item', 'sede:id,nombre', 'sala:id,codigo,nombre',
            'mantenimientos' => fn ($q) => $q->orderByDesc('inicio')->limit(20),
        ])->find($id);
        if (! $unidad) {
            throw new ModelNotFoundException("No se encontró la unidad con ID: {$id}");
        }

        return $unidad;
    }

    /**
     * @throws ValidationException
     */
    public function guardarUnidad(?int $id, array $datos): Unidad
    {
        $unidad = $id ? $this->unidad($id) : new Unidad(['estado' => 'OPERATIVO']);
        $item = Item::find($datos['item_id'] ?? $unidad->item_id);
        if (! $item || ! $item->esPorUnidad()) {
            throw ValidationException::withMessages(['item_id' => ['Elige un tipo de equipo o de caja de instrumental.']]);
        }

        $salaId = array_key_exists('sala_id', $datos) ? $datos['sala_id'] : $unidad->sala_id;
        $sedeId = $datos['sede_id'] ?? $unidad->sede_id;
        if ($salaId) {
            if ($item->tipo !== 'EQUIPO') {
                throw ValidationException::withMessages(['sala_id' => ['Solo los equipos biomédicos se instalan en una sala.']]);
            }
            if (Sala::whereKey($salaId)->value('sede_id') !== (int) $sedeId) {
                throw ValidationException::withMessages(['sala_id' => ['La sala no pertenece a la sede elegida.']]);
            }
        }

        $unidad->fill($datos)->save();

        return $this->obtenerUnidad($unidad->id);
    }

    public function eliminarUnidad(int $id): bool
    {
        return (bool) $this->unidad($id)->delete();
    }

    // ------------------------------------------------------------------ Mantenimientos

    /**
     * Programa un mantenimiento: la unidad queda bloqueada en esa franja para la programación.
     */
    public function programarMantenimiento(int $unidadId, array $datos): Mantenimiento
    {
        $this->unidad($unidadId);

        return Mantenimiento::create($datos + ['unidad_id' => $unidadId, 'estado' => 'PROGRAMADO']);
    }

    /**
     * Termina o cancela un mantenimiento. Al terminar uno preventivo o una calibración,
     * se actualizan las fechas de la unidad según la periodicidad del ítem.
     *
     * @throws ValidationException
     */
    public function cerrarMantenimiento(int $id, string $estado, ?string $observaciones): Mantenimiento
    {
        $m = Mantenimiento::with('unidad.item')->find($id);
        if (! $m) {
            throw new ModelNotFoundException("No se encontró el mantenimiento con ID: {$id}");
        }
        if ($m->estado !== 'PROGRAMADO') {
            throw ValidationException::withMessages(['estado' => ['El mantenimiento ya está cerrado.']]);
        }

        DB::transaction(function () use ($m, $estado, $observaciones) {
            $m->update(['estado' => $estado, 'observaciones' => $observaciones ?? $m->observaciones]);
            if ($estado !== 'TERMINADO') {
                return;
            }

            $unidad = $m->unidad;
            $item = $unidad->item;
            $fecha = Carbon::parse($m->fin)->startOfDay();
            $cambios = [];
            if (in_array($m->tipo, ['PREVENTIVO', 'CORRECTIVO'], true)) {
                $cambios['ultimo_mantenimiento'] = $fecha->toDateString();
                if ($m->tipo === 'PREVENTIVO' && $item->periodicidad_mantenimiento_meses) {
                    $cambios['proximo_mantenimiento'] = $fecha->copy()->addMonths($item->periodicidad_mantenimiento_meses)->toDateString();
                }
            }
            if ($m->tipo === 'CALIBRACION' && $item->periodicidad_calibracion_meses) {
                $cambios['calibracion_vence'] = $fecha->copy()->addMonths($item->periodicidad_calibracion_meses)->toDateString();
            }
            if ($unidad->estado === 'MANTENIMIENTO') {
                $cambios['estado'] = 'OPERATIVO';
            }
            $unidad->update($cambios);
        });

        return $m->refresh();
    }

    // ------------------------------------------------------------------ Salas

    public function listarSalas(array $filtros = []): Collection
    {
        return Sala::with('sede:id,nombre')
            ->withCount(['equipos' => fn ($q) => $q->where('estado', '!=', 'BAJA')])
            ->when(! empty($filtros['sede_id']), fn ($q) => $q->where('sede_id', (int) $filtros['sede_id']))
            ->when(! empty($filtros['tipo']), fn ($q) => $q->where('tipo', $filtros['tipo']))
            ->orderBy('sede_id')->orderBy('codigo')->get();
    }

    public function guardarSala(?int $id, array $datos): Sala
    {
        $sala = $id ? Sala::find($id) : new Sala(['activo' => true]);
        if (! $sala) {
            throw new ModelNotFoundException("No se encontró la sala con ID: {$id}");
        }
        $sala->fill($datos)->save();

        return $sala->load('sede:id,nombre')->loadCount('equipos');
    }

    /**
     * @throws ValidationException si tiene equipos fijos.
     */
    public function eliminarSala(int $id): bool
    {
        $sala = Sala::find($id);
        if (! $sala) {
            throw new ModelNotFoundException("No se encontró la sala con ID: {$id}");
        }
        if ($sala->equipos()->where('estado', '!=', 'BAJA')->exists()) {
            throw ValidationException::withMessages(['sala' => ['La sala tiene equipos instalados. Muévelos o márcalos como móviles primero.']]);
        }

        return (bool) $sala->delete();
    }

    private function item(int $id): Item
    {
        $item = Item::find($id);
        if (! $item) {
            throw new ModelNotFoundException("No se encontró el ítem con ID: {$id}");
        }

        return $item;
    }

    private function unidad(int $id): Unidad
    {
        $unidad = Unidad::find($id);
        if (! $unidad) {
            throw new ModelNotFoundException("No se encontró la unidad con ID: {$id}");
        }

        return $unidad;
    }
}
