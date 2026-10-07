<?php

namespace App\Modules\Red\Services;

use App\Modules\Red\Models\Prestador;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PrestadorServicio
{
    /** Columnas por las que se permite ordenar el listado. */
    private const ORDENABLES = ['id', 'razon_social', 'nit', 'created_at'];

    /** Relaciones que acompañan a un prestador en las respuestas. */
    private const RELACIONES = ['sedes' => ['municipio.departamento:id,codigo,nombre']];

    /**
     * Lista los prestadores con sus sedes, filtros y paginación.
     */
    public function listar(array $filtros = [], int $porPagina = 15): LengthAwarePaginator
    {
        $query = Prestador::query()
            ->with(['sedes' => fn ($q) => $q->with('municipio.departamento:id,codigo,nombre')->orderBy('numero_sede')])
            ->withCount('sedes');

        if (! empty($filtros['solo_eliminados'])) {
            $query->onlyTrashed();
        } elseif (! empty($filtros['incluir_eliminados'])) {
            $query->withTrashed();
        }

        if (isset($filtros['activo']) && $filtros['activo'] !== '') {
            $query->where('activo', filter_var($filtros['activo'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filtros['naturaleza'])) {
            $query->where('naturaleza', $filtros['naturaleza']);
        }

        if (! empty($filtros['buscar'])) {
            $termino = '%'.mb_strtolower(trim($filtros['buscar'])).'%';
            $query->where(function ($q) use ($termino) {
                $q->whereRaw('LOWER(razon_social) LIKE ?', [$termino])
                    ->orWhereRaw('LOWER(nombre_comercial) LIKE ?', [$termino])
                    ->orWhere('nit', 'like', $termino)
                    ->orWhere('codigo_habilitacion', 'like', $termino);
            });
        }

        $campoOrden = in_array($filtros['ordenar_por'] ?? null, self::ORDENABLES, true) ? $filtros['ordenar_por'] : 'razon_social';
        $direccion = strtolower($filtros['orden_direccion'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($campoOrden, $direccion)->paginate(max(1, min($porPagina, 100)));
    }

    /**
     * Obtiene un prestador con sus sedes.
     *
     * @throws ModelNotFoundException
     */
    public function obtenerPorId(int $id, bool $incluirEliminados = false): Prestador
    {
        $query = Prestador::with(self::RELACIONES)->withCount('sedes');

        if ($incluirEliminados) {
            $query->withTrashed();
        }

        $prestador = $query->find($id);

        if (! $prestador) {
            throw new ModelNotFoundException("No se encontró el prestador con ID: {$id}");
        }

        return $prestador;
    }

    public function crear(array $datos): Prestador
    {
        $datos['activo'] = $datos['activo'] ?? true;
        $prestador = Prestador::create($datos);

        return $this->obtenerPorId($prestador->id);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function actualizar(int $id, array $datos): Prestador
    {
        $prestador = $this->obtenerPorId($id);
        $prestador->update($datos);

        return $this->obtenerPorId($id);
    }

    /**
     * Elimina lógicamente el prestador y todas sus sedes.
     *
     * @throws ModelNotFoundException
     */
    public function eliminar(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $prestador = $this->obtenerPorId($id);
            $prestador->sedes()->get()->each->delete();

            return (bool) $prestador->delete();
        });
    }

    /**
     * Restaura el prestador y las sedes que se eliminaron junto con él.
     *
     * @throws ModelNotFoundException
     */
    public function restaurar(int $id): Prestador
    {
        return DB::transaction(function () use ($id) {
            $prestador = $this->obtenerPorId($id, true);

            if ($prestador->trashed()) {
                $eliminadoEl = $prestador->deleted_at;
                $prestador->restore();
                $prestador->sedes()->onlyTrashed()->where('deleted_at', '>=', $eliminadoEl)->get()->each->restore();
            }

            return $this->obtenerPorId($id);
        });
    }
}
