<?php

namespace App\Modules\Servicios\Services;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Servicios\Models\Especialidad;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class EspecialidadServicio
{
    /**
     * Lista las especialidades (el catálogo es pequeño: no se pagina).
     */
    public function listar(array $filtros = []): Collection
    {
        $query = Especialidad::withCount('cups');

        if (! empty($filtros['solo_eliminados'])) {
            $query->onlyTrashed();
        }

        if (isset($filtros['activo']) && $filtros['activo'] !== '') {
            $query->where('activo', filter_var($filtros['activo'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filtros['buscar'])) {
            $termino = '%'.mb_strtolower(trim($filtros['buscar'])).'%';
            $query->whereRaw('LOWER(nombre) LIKE ?', [$termino]);
        }

        return $query->orderBy('nombre')->get();
    }

    /**
     * @throws ModelNotFoundException
     */
    public function obtenerPorId(int $id, bool $incluirEliminadas = false): Especialidad
    {
        $query = Especialidad::withCount('cups');
        if ($incluirEliminadas) {
            $query->withTrashed();
        }

        $especialidad = $query->find($id);
        if (! $especialidad) {
            throw new ModelNotFoundException("No se encontró la especialidad con ID: {$id}");
        }

        return $especialidad;
    }

    public function crear(array $datos): Especialidad
    {
        $datos['activo'] = $datos['activo'] ?? true;

        return $this->obtenerPorId(Especialidad::create($datos)->id);
    }

    public function actualizar(int $id, array $datos): Especialidad
    {
        $this->obtenerPorId($id)->update($datos);

        return $this->obtenerPorId($id);
    }

    public function eliminar(int $id): bool
    {
        return (bool) $this->obtenerPorId($id)->delete();
    }

    public function restaurar(int $id): Especialidad
    {
        $especialidad = $this->obtenerPorId($id, true);
        if ($especialidad->trashed()) {
            $especialidad->restore();
        }

        return $this->obtenerPorId($id);
    }

    /**
     * Procedimientos CUPS que atiende la especialidad.
     */
    public function cupsDe(int $id): Collection
    {
        return $this->obtenerPorId($id)->cups()->orderBy('codigo')->get();
    }

    /**
     * Relaciona un CUPS con la especialidad (idempotente).
     *
     * @throws ModelNotFoundException
     */
    public function asignarCups(int $id, int $cupsId): Especialidad
    {
        $especialidad = $this->obtenerPorId($id);
        if (! Cups::whereKey($cupsId)->exists()) {
            throw new ModelNotFoundException("No se encontró el CUPS con ID: {$cupsId}");
        }

        $especialidad->cups()->syncWithoutDetaching([$cupsId]);

        return $this->obtenerPorId($id);
    }

    public function quitarCups(int $id, int $cupsId): Especialidad
    {
        $this->obtenerPorId($id)->cups()->detach($cupsId);

        return $this->obtenerPorId($id);
    }
}
