<?php

namespace App\Modules\Contratacion\Services;

use App\Modules\Contratacion\Models\Contrato;
use App\Modules\Contratacion\Models\Poblacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PoblacionServicio
{
    /**
     * Lista poblaciones con su contrato, entidad, pacientes activos y último cargue.
     */
    public function listar(array $filtros = [], int $porPagina = 20): LengthAwarePaginator
    {
        $query = $this->base();

        if (! empty($filtros['solo_eliminados'])) {
            $query->onlyTrashed();
        }

        if (isset($filtros['activo']) && $filtros['activo'] !== '') {
            $query->where('activo', filter_var($filtros['activo'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filtros['contrato_id'])) {
            $query->where('contrato_id', (int) $filtros['contrato_id']);
        }

        if (! empty($filtros['entidad_id'])) {
            $query->whereHas('contrato', fn ($q) => $q->where('entidad_id', (int) $filtros['entidad_id']));
        }

        if (! empty($filtros['sin_cargue_mes'])) {
            $query->where(fn ($q) => $q->whereNull('ultimo_cargue_en')->orWhere('ultimo_cargue_en', '<', now()->startOfMonth()));
        }

        if (! empty($filtros['buscar'])) {
            $termino = '%'.mb_strtolower(trim($filtros['buscar'])).'%';
            $query->where(function ($q) use ($termino) {
                $q->whereRaw('LOWER(nombre) LIKE ?', [$termino])
                    ->orWhereHas('contrato', fn ($c) => $c->whereRaw('LOWER(numero) LIKE ?', [$termino]));
            });
        }

        return $query->orderBy('nombre')->paginate(max(1, min($porPagina, 100)));
    }

    /**
     * Cifras para el tablero y la cabecera de Poblaciones.
     *
     * @return array{poblaciones: int, pacientes: int, sin_cargue_mes: int}
     */
    public function resumen(): array
    {
        $activas = Poblacion::where('activo', true)->whereHas('contrato', fn ($q) => $q->enEstado('EN_EJECUCION'));

        return [
            'poblaciones' => (clone $activas)->count(),
            'pacientes' => (int) DB::table('poblacion_paciente')
                ->join('poblaciones', 'poblaciones.id', '=', 'poblacion_paciente.poblacion_id')
                ->where('poblacion_paciente.activo', true)
                ->where('poblaciones.activo', true)
                ->whereNull('poblaciones.deleted_at')
                ->distinct()
                ->count('poblacion_paciente.paciente_id'),
            'sin_cargue_mes' => (clone $activas)->where(fn ($q) => $q->whereNull('ultimo_cargue_en')->orWhere('ultimo_cargue_en', '<', now()->startOfMonth()))->count(),
        ];
    }

    /**
     * Detalle con los últimos cargues y el conteo de pacientes por cohorte.
     *
     * @throws ModelNotFoundException
     */
    public function obtenerPorId(int $id, bool $incluirEliminadas = false): Poblacion
    {
        $query = $this->base()->with(['cargues' => fn ($q) => $q->with('usuario:id,name')->latest()->limit(10)]);
        if ($incluirEliminadas) {
            $query->withTrashed();
        }

        $poblacion = $query->find($id);
        if (! $poblacion) {
            throw new ModelNotFoundException("No se encontró la población con ID: {$id}");
        }

        $cohortes = [];
        DB::table('poblacion_paciente')->where('poblacion_id', $id)->where('activo', true)->whereNotNull('cohortes')
            ->select('id', 'cohortes')->orderBy('id')
            ->chunk(5000, function ($filas) use (&$cohortes) {
                foreach ($filas as $fila) {
                    foreach (json_decode($fila->cohortes, true) ?: [] as $cohorte) {
                        $cohortes[$cohorte] = ($cohortes[$cohorte] ?? 0) + 1;
                    }
                }
            });
        arsort($cohortes);
        $poblacion->setAttribute('cohortes', collect($cohortes)->map(fn ($n, $nombre) => ['nombre' => $nombre, 'pacientes' => $n])->values());

        return $poblacion;
    }

    /**
     * Pacientes de la población (por defecto, solo los activos).
     */
    public function pacientes(int $id, array $filtros = [], int $porPagina = 25): LengthAwarePaginator
    {
        $poblacion = $this->buscar($id);
        $query = $poblacion->pacientes()->with('tipoDocumento:id,codigo')->with('municipio:id,codigo,nombre');

        if (empty($filtros['incluir_retirados'])) {
            $query->wherePivot('activo', true);
        }

        if (! empty($filtros['cohorte'])) {
            $cohorte = json_encode((string) $filtros['cohorte'], JSON_UNESCAPED_UNICODE);
            $query->whereRaw('CAST(poblacion_paciente.cohortes AS TEXT) LIKE ?', ["%{$cohorte}%"]);
        }

        if (! empty($filtros['buscar'])) {
            foreach (preg_split('/\s+/', mb_strtolower(trim($filtros['buscar']))) as $palabra) {
                $termino = "%{$palabra}%";
                $query->where(function ($q) use ($termino) {
                    $q->where('numero_documento', 'like', $termino)
                        ->orWhereRaw('LOWER(primer_nombre) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(segundo_nombre) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(primer_apellido) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(segundo_apellido) LIKE ?', [$termino]);
                });
            }
        }

        $paginador = $query->orderBy('primer_apellido')->orderBy('primer_nombre')->paginate(max(1, min($porPagina, 100)));
        $paginador->getCollection()->transform(function ($paciente) {
            $paciente->setAttribute('cohortes', json_decode($paciente->pivot->cohortes ?? '[]', true) ?: []);
            $paciente->setAttribute('activo_en_poblacion', (bool) $paciente->pivot->activo);
            $paciente->makeHidden('pivot');

            return $paciente;
        });

        return $paginador;
    }

    public function crear(array $datos): Poblacion
    {
        $datos['activo'] = $datos['activo'] ?? true;

        return $this->obtenerPorId(Poblacion::create($datos)->id);
    }

    public function actualizar(int $id, array $datos): Poblacion
    {
        $this->buscar($id)->update($datos);

        return $this->obtenerPorId($id);
    }

    public function eliminar(int $id): bool
    {
        return (bool) $this->buscar($id)->delete();
    }

    /**
     * @throws ValidationException si el contrato está eliminado.
     */
    public function restaurar(int $id): Poblacion
    {
        $poblacion = $this->buscar($id, true);
        if ($poblacion->trashed()) {
            if (! Contrato::whereKey($poblacion->contrato_id)->exists()) {
                throw ValidationException::withMessages(['contrato_id' => ['Restaura primero el contrato de esta población.']]);
            }
            $poblacion->restore();
        }

        return $this->obtenerPorId($id);
    }

    public function buscar(int $id, bool $incluirEliminadas = false): Poblacion
    {
        $poblacion = $incluirEliminadas ? Poblacion::withTrashed()->find($id) : Poblacion::find($id);
        if (! $poblacion) {
            throw new ModelNotFoundException("No se encontró la población con ID: {$id}");
        }

        return $poblacion;
    }

    private function base(): Builder
    {
        return Poblacion::query()
            ->with([
                'contrato:id,entidad_id,numero,modalidad_contratacion_id,regimen_id,fecha_inicio,fecha_fin,activo',
                'contrato.entidad:id,razon_social,sigla',
                'contrato.modalidad:id,codigo,nombre',
                'contrato.regimen:id,codigo,nombre',
            ])
            ->withCount(['pacientes as pacientes_count' => fn ($q) => $q->where('poblacion_paciente.activo', true)]);
    }
}
