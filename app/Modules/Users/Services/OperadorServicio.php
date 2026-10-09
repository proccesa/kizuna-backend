<?php

namespace App\Modules\Users\Services;

use App\Modules\Users\Models\Operador;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

class OperadorServicio
{
    /**
     * Lista los operadores con filtros y paginación.
     */
    public function listar(array $filtros = [], int $porPagina = 15): LengthAwarePaginator
    {
        $query = Operador::with(['tipoDocumento', 'usuario']);

        if (! empty($filtros['solo_eliminados'])) {
            $query->onlyTrashed();
        } elseif (! empty($filtros['incluir_eliminados'])) {
            $query->withTrashed();
        }

        if (isset($filtros['activo']) && $filtros['activo'] !== '') {
            $query->where('activo', filter_var($filtros['activo'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filtros['tipo_documento_id'])) {
            $query->where('tipo_documento_id', $filtros['tipo_documento_id']);
        }

        if (! empty($filtros['buscar'])) {
            $termino = '%'.trim($filtros['buscar']).'%';
            $query->where(function ($q) use ($termino) {
                $q->where('nombre', 'ilike', $termino)
                    ->orWhere('apellido', 'ilike', $termino)
                    ->orWhere('documento', 'ilike', $termino)
                    ->orWhere('telefono', 'ilike', $termino);
            });
        }

        $campoOrden = $filtros['ordenar_por'] ?? 'id';
        $direccionOrden = strtolower($filtros['orden_direccion'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($campoOrden, $direccionOrden)->paginate($porPagina);
    }

    /**
     * Obtiene un operador por ID.
     *
     * @throws ModelNotFoundException
     */
    public function obtenerPorId(int $id, bool $incluirEliminados = false): Operador
    {
        $query = Operador::with(['tipoDocumento', 'usuario']);

        if ($incluirEliminados) {
            $query->withTrashed();
        }

        $operador = $query->find($id);

        if (! $operador) {
            throw new ModelNotFoundException("No se encontró el operador con ID: {$id}");
        }

        return $operador;
    }

    /**
     * Crea un nuevo registro de operador.
     */
    public function crear(array $datos): Operador
    {
        $datos['activo'] = $datos['activo'] ?? true;
        $operador = Operador::create($datos);

        return $operador->fresh(['tipoDocumento', 'usuario']);
    }

    /**
     * Actualiza la información de un operador.
     *
     * @throws ModelNotFoundException
     */
    public function actualizar(int $id, array $datos): Operador
    {
        $operador = $this->obtenerPorId($id);
        $operador->update($datos);

        return $operador->fresh(['tipoDocumento', 'usuario']);
    }

    /**
     * Elimina lógicamente a un operador.
     *
     * @throws ModelNotFoundException
     */
    public function eliminar(int $id): bool
    {
        $operador = $this->obtenerPorId($id);

        return (bool) $operador->delete();
    }

    /**
     * Restaura un operador eliminado con soft delete.
     *
     * @throws ModelNotFoundException
     */
    public function restaurar(int $id): bool
    {
        $operador = $this->obtenerPorId($id, true);

        if ($operador->trashed()) {
            return (bool) $operador->restore();
        }

        return true;
    }
}
