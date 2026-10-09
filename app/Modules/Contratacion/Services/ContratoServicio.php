<?php

namespace App\Modules\Contratacion\Services;

use App\Modules\Contratacion\Models\Contrato;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContratoServicio
{
    private const RELACIONES = [
        'entidad:id,nit,digito_verificacion,razon_social,sigla',
        'modalidad:id,codigo,nombre',
        'regimen:id,codigo,nombre',
    ];

    public function __construct(
        private readonly CumplimientoContratos $cumplimiento
    ) {}

    /**
     * Lista contratos (filtros: entidad, modalidad, régimen, estado, búsqueda).
     */
    public function listar(array $filtros = [], int $porPagina = 20): LengthAwarePaginator
    {
        $query = $this->base();

        if (! empty($filtros['solo_eliminados'])) {
            $query->onlyTrashed();
        }

        foreach (['entidad_id', 'modalidad_contratacion_id', 'regimen_id'] as $campo) {
            if (! empty($filtros[$campo])) {
                $query->where($campo, (int) $filtros[$campo]);
            }
        }

        if (! empty($filtros['estado'])) {
            $query->enEstado(strtoupper($filtros['estado']));
        }

        if (! empty($filtros['buscar'])) {
            $termino = '%'.mb_strtolower(trim($filtros['buscar'])).'%';
            $query->where(function ($q) use ($termino) {
                $q->whereRaw('LOWER(numero) LIKE ?', [$termino])
                    ->orWhereHas('entidad', fn ($e) => $e->whereRaw('LOWER(razon_social) LIKE ?', [$termino])->orWhereRaw('LOWER(sigla) LIKE ?', [$termino]));
            });
        }

        $paginador = $query->orderByDesc('fecha_inicio')->orderBy('numero')->paginate(max(1, min($porPagina, 100)));
        $cumplimiento = $this->cumplimiento->resumen($paginador->getCollection());
        $paginador->getCollection()->each(fn (Contrato $c) => $c->setAttribute('cumplimiento', $cumplimiento[$c->id] ?? null));

        return $paginador;
    }

    /**
     * Cifras de los contratos en ejecución, agrupadas por modalidad.
     */
    public function resumen(): array
    {
        $enEjecucion = Contrato::enEstado('EN_EJECUCION')->with('modalidad:id,codigo,nombre')->get(['id', 'modalidad_contratacion_id', 'valor', 'fecha_fin', 'activo', 'fecha_inicio']);

        return [
            'en_ejecucion' => $enEjecucion->count(),
            'por_vencer' => Contrato::enEstado('POR_VENCER')->count(),
            'vencidos' => Contrato::enEstado('VENCIDO')->count(),
            'por_modalidad' => $enEjecucion->groupBy('modalidad_contratacion_id')->map(fn ($grupo) => [
                'modalidad' => $grupo->first()->modalidad?->only(['id', 'codigo', 'nombre']),
                'cantidad' => $grupo->count(),
                'valor' => round($grupo->sum('valor'), 2),
            ])->values(),
        ];
    }

    /**
     * Detalle con sedes, poblaciones y el estado de los CUPS frente al portafolio.
     *
     * @throws ModelNotFoundException
     */
    public function obtenerPorId(int $id, bool $incluirEliminados = false): Contrato
    {
        $query = $this->base()->with([
            'sedes:id,nombre,prestador_id,activo',
            'sedes.prestador:id,razon_social,nombre_comercial',
            'poblaciones' => fn ($q) => $q->withCount(['pacientes as pacientes_count' => fn ($p) => $p->where('poblacion_paciente.activo', true)])->orderBy('nombre'),
        ]);
        if ($incluirEliminados) {
            $query->withTrashed();
        }

        $contrato = $query->find($id);
        if (! $contrato) {
            throw new ModelNotFoundException("No se encontró el contrato con ID: {$id}");
        }

        $contrato->setAttribute('cups_sin_portafolio_count', $this->cupsSinPortafolio($contrato)->count());
        $contrato->setAttribute('cumplimiento', $this->cumplimiento->resumen([$contrato])[$contrato->id]);

        return $contrato;
    }

    public function crear(array $datos): Contrato
    {
        return DB::transaction(function () use ($datos) {
            $sedes = $datos['sede_ids'] ?? [];
            unset($datos['sede_ids']);
            $datos['activo'] = $datos['activo'] ?? true;
            $datos['valor'] = $datos['valor'] ?? 0;

            $contrato = Contrato::create($datos);
            $contrato->sedes()->sync($sedes);

            return $this->obtenerPorId($contrato->id);
        });
    }

    public function actualizar(int $id, array $datos): Contrato
    {
        return DB::transaction(function () use ($id, $datos) {
            $contrato = $this->buscar($id);
            if (array_key_exists('sede_ids', $datos)) {
                $contrato->sedes()->sync($datos['sede_ids'] ?? []);
                unset($datos['sede_ids']);
            }
            $contrato->update($datos);

            return $this->obtenerPorId($id);
        });
    }

    /**
     * Elimina lógicamente el contrato junto con sus poblaciones.
     */
    public function eliminar(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $contrato = $this->buscar($id);
            $contrato->poblaciones()->get()->each->delete();

            return (bool) $contrato->delete();
        });
    }

    /**
     * Restaura el contrato y las poblaciones que se eliminaron con él. La entidad debe estar vigente.
     *
     * @throws ValidationException
     */
    public function restaurar(int $id): Contrato
    {
        return DB::transaction(function () use ($id) {
            $contrato = $this->buscar($id, true);
            if ($contrato->trashed()) {
                if (! $contrato->entidad()->exists()) {
                    throw ValidationException::withMessages(['entidad_id' => ['Restaura primero la entidad de este contrato.']]);
                }
                if (Contrato::where('entidad_id', $contrato->entidad_id)->where('numero', $contrato->numero)->exists()) {
                    throw ValidationException::withMessages(['numero' => ['La entidad ya tiene otro contrato activo con este número.']]);
                }

                $eliminadoEl = $contrato->deleted_at;
                $contrato->restore();
                $contrato->poblaciones()->onlyTrashed()->where('deleted_at', '>=', $eliminadoEl)->get()->each->restore();
            }

            return $this->obtenerPorId($id);
        });
    }

    /**
     * CUPS pactados, paginados, con `en_portafolio` (alguna sede del contrato lo ofrece)
     * y `con_especialidad` (alguna especialidad lo atiende).
     */
    public function cups(int $id, array $filtros = [], int $porPagina = 25): LengthAwarePaginator
    {
        $contrato = $this->buscar($id);
        $sedeIds = $contrato->sedes()->pluck('sedes.id');

        $query = $contrato->cups()
            ->select('cups.id', 'cups.codigo', 'cups.nombre', 'cups.habilitado')
            ->selectSub($this->existeEnPortafolio($sedeIds->all()), 'en_portafolio')
            ->selectSub(DB::table('cups_especialidad')->whereColumn('cups_especialidad.cups_id', 'cups.id')->selectRaw('COUNT(*)'), 'especialidades_count');

        if (! empty($filtros['buscar'])) {
            $termino = trim($filtros['buscar']);
            $query->where(fn ($q) => $q->where('cups.codigo', 'like', "{$termino}%")->orWhere('cups.nombre_normalizado', 'like', '%'.mb_strtoupper(Str::ascii($termino)).'%'));
        }

        if (! empty($filtros['sin_portafolio'])) {
            $query->whereNotIn('cups.id', $this->cupsEnPortafolio($sedeIds->all()));
        }

        $paginador = $query->orderBy('cups.codigo')->paginate(max(1, min($porPagina, 100)));
        $conteos = $this->cumplimiento->porCups($contrato->id);
        $paginador->getCollection()->transform(fn ($c) => [
            'id' => $c->id,
            'codigo' => $c->codigo,
            'nombre' => $c->nombre,
            'habilitado' => (bool) $c->habilitado,
            'cantidad' => $c->pivot->cantidad,
            'tarifa' => $c->pivot->tarifa !== null ? (float) $c->pivot->tarifa : null,
            'en_portafolio' => (int) $c->en_portafolio > 0,
            'con_especialidad' => (int) $c->especialidades_count > 0,
            'realizadas' => $conteos[$c->id]['realizadas'] ?? 0,
            'programadas' => $conteos[$c->id]['programadas'] ?? 0,
        ]);

        return $paginador;
    }

    /**
     * Agrega CUPS al contrato (omite los que ya estaban).
     *
     * @return array{creados: int, existentes: int}
     */
    public function agregarCups(int $id, array $datos): array
    {
        $contrato = $this->buscar($id);
        $existentes = $contrato->cups()->whereIn('cups.id', $datos['cups_ids'])->pluck('cups.id')->all();
        $nuevos = array_values(array_diff($datos['cups_ids'], $existentes));

        $contrato->cups()->attach(
            collect($nuevos)->mapWithKeys(fn ($cupsId) => [$cupsId => ['cantidad' => $datos['cantidad'] ?? null, 'tarifa' => $datos['tarifa'] ?? null]])->all()
        );

        return ['creados' => count($nuevos), 'existentes' => count($existentes)];
    }

    /**
     * @throws ModelNotFoundException
     */
    public function actualizarCups(int $id, int $cupsId, array $datos): bool
    {
        $contrato = $this->buscar($id);
        if (! $contrato->cups()->whereKey($cupsId)->exists()) {
            throw new ModelNotFoundException('El CUPS no está pactado en este contrato.');
        }
        $contrato->cups()->updateExistingPivot($cupsId, array_intersect_key($datos, array_flip(['cantidad', 'tarifa'])));

        return true;
    }

    public function quitarCups(int $id, int $cupsId): bool
    {
        $this->buscar($id)->cups()->detach($cupsId);

        return true;
    }

    private function base(): EloquentBuilder
    {
        return Contrato::query()
            ->select('contratos.*')
            ->addSelect(['pacientes_count' => DB::table('poblacion_paciente')
                ->join('poblaciones', 'poblaciones.id', '=', 'poblacion_paciente.poblacion_id')
                ->whereColumn('poblaciones.contrato_id', 'contratos.id')
                ->where('poblacion_paciente.activo', true)
                ->whereNull('poblaciones.deleted_at')
                ->selectRaw('COUNT(DISTINCT poblacion_paciente.paciente_id)')])
            ->with(self::RELACIONES)
            ->withCount(['cups', 'sedes', 'poblaciones']);
    }

    private function cupsEnPortafolio(array $sedeIds): Builder
    {
        return DB::table('portafolio_servicios')->whereIn('sede_id', $sedeIds ?: [0])->where('activo', true)->select('cups_id');
    }

    private function existeEnPortafolio(array $sedeIds): Builder
    {
        return DB::table('portafolio_servicios')
            ->whereColumn('portafolio_servicios.cups_id', 'cups.id')
            ->whereIn('portafolio_servicios.sede_id', $sedeIds ?: [0])
            ->where('portafolio_servicios.activo', true)
            ->selectRaw('COUNT(*)');
    }

    private function cupsSinPortafolio(Contrato $contrato)
    {
        return $contrato->cups()->whereNotIn('cups.id', $this->cupsEnPortafolio($contrato->sedes->pluck('id')->all()));
    }

    private function buscar(int $id, bool $incluirEliminados = false): Contrato
    {
        $contrato = $incluirEliminados ? Contrato::withTrashed()->find($id) : Contrato::find($id);
        if (! $contrato) {
            throw new ModelNotFoundException("No se encontró el contrato con ID: {$id}");
        }

        return $contrato;
    }
}
