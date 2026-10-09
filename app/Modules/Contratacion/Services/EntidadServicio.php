<?php

namespace App\Modules\Contratacion\Services;

use App\Modules\Contratacion\Models\Entidad;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntidadServicio
{
    /**
     * Lista entidades con sus regímenes, cuántos contratos tienen en ejecución y cuántos pacientes.
     */
    public function listar(array $filtros = [], int $porPagina = 20): LengthAwarePaginator
    {
        $query = Entidad::query()
            ->select('entidades.*')
            ->addSelect(['pacientes_count' => $this->subconsultaPacientes()])
            ->with('regimenes:id,codigo,nombre')
            ->withCount(['contratos', 'contratos as contratos_en_ejecucion_count' => fn ($q) => $q->enEstado('EN_EJECUCION')]);

        if (! empty($filtros['solo_eliminados'])) {
            $query->onlyTrashed();
        }

        if (isset($filtros['activo']) && $filtros['activo'] !== '') {
            $query->where('activo', filter_var($filtros['activo'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filtros['tipo'])) {
            $query->where('tipo', $filtros['tipo']);
        }

        if (! empty($filtros['regimen_id'])) {
            $query->whereHas('regimenes', fn ($q) => $q->whereKey((int) $filtros['regimen_id']));
        }

        if (! empty($filtros['buscar'])) {
            $termino = '%'.mb_strtolower(trim($filtros['buscar'])).'%';
            $query->where(function ($q) use ($termino) {
                $q->whereRaw('LOWER(razon_social) LIKE ?', [$termino])
                    ->orWhereRaw('LOWER(sigla) LIKE ?', [$termino])
                    ->orWhereRaw('LOWER(codigo_minsalud) LIKE ?', [$termino])
                    ->orWhere('nit', 'like', $termino);
            });
        }

        return $query->orderBy('razon_social')->paginate(max(1, min($porPagina, 100)));
    }

    /**
     * @throws ModelNotFoundException
     */
    public function obtenerPorId(int $id, bool $incluirEliminadas = false): Entidad
    {
        $query = Entidad::query()
            ->select('entidades.*')
            ->addSelect(['pacientes_count' => $this->subconsultaPacientes()])
            ->with('regimenes:id,codigo,nombre')
            ->withCount(['contratos', 'contratos as contratos_en_ejecucion_count' => fn ($q) => $q->enEstado('EN_EJECUCION')]);

        if ($incluirEliminadas) {
            $query->withTrashed();
        }

        $entidad = $query->find($id);
        if (! $entidad) {
            throw new ModelNotFoundException("No se encontró la entidad con ID: {$id}");
        }

        return $entidad;
    }

    public function crear(array $datos): Entidad
    {
        return DB::transaction(function () use ($datos) {
            $regimenes = $datos['regimen_ids'] ?? [];
            unset($datos['regimen_ids']);
            $datos['activo'] = $datos['activo'] ?? true;

            $entidad = Entidad::create($datos);
            $entidad->regimenes()->sync($regimenes);

            return $this->obtenerPorId($entidad->id);
        });
    }

    public function actualizar(int $id, array $datos): Entidad
    {
        return DB::transaction(function () use ($id, $datos) {
            $entidad = $this->buscar($id);
            if (array_key_exists('regimen_ids', $datos)) {
                $entidad->regimenes()->sync($datos['regimen_ids'] ?? []);
                unset($datos['regimen_ids']);
            }
            $entidad->update($datos);

            return $this->obtenerPorId($id);
        });
    }

    /**
     * @throws ValidationException si la entidad tiene contratos.
     */
    public function eliminar(int $id): bool
    {
        $entidad = $this->buscar($id);
        $contratos = $entidad->contratos()->count();
        if ($contratos > 0) {
            throw ValidationException::withMessages([
                'entidad' => ["La entidad tiene {$contratos} ".($contratos === 1 ? 'contrato' : 'contratos').'. Elimínalos primero o desactiva la entidad.'],
            ]);
        }

        return (bool) $entidad->delete();
    }

    /**
     * @throws ValidationException si otra entidad activa ya usa el NIT.
     */
    public function restaurar(int $id): Entidad
    {
        $entidad = $this->buscar($id, true);
        if ($entidad->trashed()) {
            if (Entidad::where('nit', $entidad->nit)->exists()) {
                throw ValidationException::withMessages(['nit' => ['Ya existe otra entidad activa con este NIT.']]);
            }
            $entidad->restore();
        }

        return $this->obtenerPorId($id);
    }

    /**
     * Pacientes activos (distintos) en las poblaciones de los contratos de la entidad.
     */
    private function subconsultaPacientes(): Builder
    {
        return DB::table('poblacion_paciente')
            ->join('poblaciones', 'poblaciones.id', '=', 'poblacion_paciente.poblacion_id')
            ->join('contratos', 'contratos.id', '=', 'poblaciones.contrato_id')
            ->whereColumn('contratos.entidad_id', 'entidades.id')
            ->where('poblacion_paciente.activo', true)
            ->whereNull('poblaciones.deleted_at')
            ->whereNull('contratos.deleted_at')
            ->selectRaw('COUNT(DISTINCT poblacion_paciente.paciente_id)');
    }

    private function buscar(int $id, bool $incluirEliminadas = false): Entidad
    {
        $entidad = $incluirEliminadas ? Entidad::withTrashed()->find($id) : Entidad::find($id);
        if (! $entidad) {
            throw new ModelNotFoundException("No se encontró la entidad con ID: {$id}");
        }

        return $entidad;
    }
}
