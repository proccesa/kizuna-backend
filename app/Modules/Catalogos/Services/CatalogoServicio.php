<?php

namespace App\Modules\Catalogos\Services;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Catalogos\Models\Departamento;
use App\Modules\Catalogos\Models\ModalidadContratacion;
use App\Modules\Catalogos\Models\Municipio;
use App\Modules\Catalogos\Models\Regimen;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class CatalogoServicio
{
    /**
     * Máximo de registros por página en búsquedas de municipios.
     */
    private const MAX_POR_PAGINA = 100;

    /**
     * Lista los departamentos con la cantidad de municipios de cada uno.
     */
    public function listarDepartamentos(): Collection
    {
        return Departamento::withCount('municipios')->orderBy('nombre')->get();
    }

    /**
     * Lista los municipios activos de un departamento.
     *
     * @throws ModelNotFoundException
     */
    public function listarMunicipiosPorDepartamento(int $departamentoId): Collection
    {
        if (! Departamento::whereKey($departamentoId)->exists()) {
            throw new ModelNotFoundException("No se encontró el departamento con ID: {$departamentoId}");
        }

        return Municipio::activos()
            ->where('departamento_id', $departamentoId)
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Busca municipios por nombre o código DANE, con filtro opcional por departamento.
     * La búsqueda ignora tildes y mayúsculas ("medellin" encuentra "Medellín").
     */
    public function buscarMunicipios(array $filtros = [], int $porPagina = 20): LengthAwarePaginator
    {
        $query = Municipio::activos()->with('departamento:id,codigo,nombre');

        if (! empty($filtros['departamento_id'])) {
            $query->where('departamento_id', (int) $filtros['departamento_id']);
        }

        if (! empty($filtros['buscar'])) {
            $termino = trim($filtros['buscar']);
            $normalizado = Str::upper(Str::ascii($termino));

            $query->where(function ($q) use ($termino, $normalizado) {
                $q->where('nombre_normalizado', 'like', "%{$normalizado}%")
                    ->orWhere('codigo', 'like', "{$termino}%");
            });
        }

        $porPagina = max(1, min($porPagina, self::MAX_POR_PAGINA));

        return $query->orderBy('nombre')->paginate($porPagina);
    }

    /**
     * Obtiene un municipio con su departamento.
     *
     * @throws ModelNotFoundException
     */
    public function obtenerMunicipio(int $id): Municipio
    {
        $municipio = Municipio::with('departamento:id,codigo,nombre')->find($id);

        if (! $municipio) {
            throw new ModelNotFoundException("No se encontró el municipio con ID: {$id}");
        }

        return $municipio;
    }

    /**
     * Lista los regímenes de afiliación activos.
     */
    public function listarRegimenes(): Collection
    {
        return Regimen::activos()->orderBy('id')->get();
    }

    /**
     * Lista las modalidades de contratación activas.
     */
    public function listarModalidadesContratacion(): Collection
    {
        return ModalidadContratacion::activos()->orderBy('id')->get();
    }

    /**
     * Busca procedimientos CUPS. Si el término es numérico busca por código (prefijo);
     * si no, cada palabra debe aparecer en el nombre (sin tildes ni mayúsculas).
     */
    public function buscarCups(array $filtros = [], int $porPagina = 20): LengthAwarePaginator
    {
        $query = Cups::query()->with('especialidades:id,codigo,nombre');

        $habilitado = $filtros['habilitado'] ?? '1';
        if ($habilitado !== '' && $habilitado !== 'todos') {
            $query->where('habilitado', filter_var($habilitado, FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filtros['buscar'])) {
            $termino = trim($filtros['buscar']);
            if (ctype_digit($termino)) {
                $query->where('codigo', 'like', "{$termino}%");
            } else {
                foreach (preg_split('/\s+/', Str::upper(Str::ascii($termino))) as $palabra) {
                    $query->where('nombre_normalizado', 'like', "%{$palabra}%");
                }
            }
        }

        return $query->orderBy('codigo')->paginate(max(1, min($porPagina, self::MAX_POR_PAGINA)));
    }

    /**
     * @throws ModelNotFoundException
     */
    public function obtenerCups(int $id): Cups
    {
        $cups = Cups::with('especialidades:id,codigo,nombre')->find($id);

        if (! $cups) {
            throw new ModelNotFoundException("No se encontró el CUPS con ID: {$id}");
        }

        return $cups;
    }
}
