<?php

namespace App\Modules\Red\Services;

use App\Modules\Red\Models\Prestador;
use App\Modules\Red\Models\Sede;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SedeServicio
{
    private const RELACIONES = ['municipio.departamento:id,codigo,nombre', 'prestador:id,nit,digito_verificacion,razon_social,nombre_comercial,activo'];

    /**
     * Lista sedes de toda la red (para selectores y filtros), paginadas.
     */
    public function listar(array $filtros = [], int $porPagina = 50): LengthAwarePaginator
    {
        $query = Sede::with(self::RELACIONES);

        if (! empty($filtros['solo_eliminados'])) {
            $query->onlyTrashed();
        }

        foreach (['prestador_id', 'municipio_id'] as $campo) {
            if (! empty($filtros[$campo])) {
                $query->where($campo, (int) $filtros[$campo]);
            }
        }

        if (isset($filtros['activo']) && $filtros['activo'] !== '') {
            $query->where('activo', filter_var($filtros['activo'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filtros['buscar'])) {
            $termino = '%'.mb_strtolower(trim($filtros['buscar'])).'%';
            $query->where(function ($q) use ($termino) {
                $q->whereRaw('LOWER(nombre) LIKE ?', [$termino])->orWhereRaw('LOWER(direccion) LIKE ?', [$termino]);
            });
        }

        return $query->orderBy('prestador_id')->orderBy('numero_sede')->paginate(max(1, min($porPagina, 100)));
    }

    /**
     * Sedes de un prestador (incluye las inactivas, no las eliminadas).
     *
     * @throws ModelNotFoundException
     */
    public function listarDePrestador(int $prestadorId): Collection
    {
        $this->prestadorVigente($prestadorId);

        return Sede::with('municipio.departamento:id,codigo,nombre')
            ->where('prestador_id', $prestadorId)
            ->orderBy('numero_sede')
            ->get();
    }

    /**
     * @throws ModelNotFoundException
     */
    public function obtenerPorId(int $id, bool $incluirEliminadas = false): Sede
    {
        $query = Sede::with(self::RELACIONES);

        if ($incluirEliminadas) {
            $query->withTrashed();
        }

        $sede = $query->find($id);

        if (! $sede) {
            throw new ModelNotFoundException("No se encontró la sede con ID: {$id}");
        }

        return $sede;
    }

    /**
     * Crea una sede. La primera sede del prestador queda como principal.
     *
     * @throws ModelNotFoundException
     */
    public function crear(int $prestadorId, array $datos): Sede
    {
        $this->prestadorVigente($prestadorId);

        return DB::transaction(function () use ($prestadorId, $datos) {
            $esPrimera = ! Sede::where('prestador_id', $prestadorId)->exists();
            $datos['prestador_id'] = $prestadorId;
            $datos['es_principal'] = $esPrimera || ! empty($datos['es_principal']);
            $datos['activo'] = $datos['activo'] ?? true;

            $sede = Sede::create($datos);

            if ($sede->es_principal) {
                $this->quitarPrincipalAOtras($sede);
            }

            return $this->obtenerPorId($sede->id);
        });
    }

    /**
     * @throws ModelNotFoundException
     */
    public function actualizar(int $id, array $datos): Sede
    {
        return DB::transaction(function () use ($id, $datos) {
            $sede = $this->obtenerPorId($id);
            unset($datos['prestador_id']);
            $sede->update($datos);

            if (! empty($datos['es_principal'])) {
                $this->quitarPrincipalAOtras($sede);
            }

            return $this->obtenerPorId($id);
        });
    }

    /**
     * Elimina lógicamente la sede. Si era la principal, otra sede activa toma su lugar.
     *
     * @throws ModelNotFoundException
     */
    public function eliminar(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $sede = $this->obtenerPorId($id);
            $eraPrincipal = $sede->es_principal;
            $sede->update(['es_principal' => false]);
            $sede->delete();

            if ($eraPrincipal) {
                Sede::where('prestador_id', $sede->prestador_id)->orderBy('numero_sede')->first()?->update(['es_principal' => true]);
            }

            return true;
        });
    }

    /**
     * Restaura una sede eliminada. El prestador debe estar vigente.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function restaurar(int $id): Sede
    {
        $sede = $this->obtenerPorId($id, true);

        if (Prestador::onlyTrashed()->whereKey($sede->prestador_id)->exists()) {
            throw ValidationException::withMessages([
                'prestador_id' => ['Restaura primero el prestador de esta sede.'],
            ]);
        }

        $duplicada = Sede::where('prestador_id', $sede->prestador_id)->where('numero_sede', $sede->numero_sede)->exists();
        if ($duplicada) {
            throw ValidationException::withMessages([
                'numero_sede' => ["Ya existe otra sede activa con el número {$sede->numero_sede}."],
            ]);
        }

        if ($sede->trashed()) {
            $sede->restore();
        }

        return $this->obtenerPorId($id);
    }

    /**
     * @throws ModelNotFoundException
     */
    private function prestadorVigente(int $prestadorId): Prestador
    {
        $prestador = Prestador::find($prestadorId);

        if (! $prestador) {
            throw new ModelNotFoundException("No se encontró el prestador con ID: {$prestadorId}");
        }

        return $prestador;
    }

    private function quitarPrincipalAOtras(Sede $sede): void
    {
        Sede::where('prestador_id', $sede->prestador_id)->whereKeyNot($sede->id)->update(['es_principal' => false]);
    }
}
