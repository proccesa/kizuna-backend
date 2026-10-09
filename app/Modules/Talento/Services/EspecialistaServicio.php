<?php

namespace App\Modules\Talento\Services;

use App\Modules\Talento\Models\Agenda;
use App\Modules\Talento\Models\Ausencia;
use App\Modules\Talento\Models\Especialista;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EspecialistaServicio
{
    private const RELACIONES_LISTADO = [
        'tipoDocumento:id,codigo,nombre',
        'especialidades:id,codigo,nombre',
    ];

    /**
     * Lista especialistas con sus especialidades, las sedes donde atienden y las horas semanales de agenda vigente.
     */
    public function listar(array $filtros = [], int $porPagina = 20): LengthAwarePaginator
    {
        $query = Especialista::with(self::RELACIONES_LISTADO + [
            'agendas' => fn ($q) => $q->vigentes()->with('sede:id,nombre,prestador_id'),
        ]);

        if (! empty($filtros['solo_eliminados'])) {
            $query->onlyTrashed();
        }

        if (isset($filtros['activo']) && $filtros['activo'] !== '') {
            $query->where('activo', filter_var($filtros['activo'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filtros['especialidad_id'])) {
            $query->whereHas('especialidades', fn ($q) => $q->whereKey((int) $filtros['especialidad_id']));
        }

        if (! empty($filtros['sede_id'])) {
            $query->whereHas('agendas', fn ($q) => $q->vigentes()->where('sede_id', (int) $filtros['sede_id']));
        }

        if (! empty($filtros['sin_agenda'])) {
            $query->whereDoesntHave('agendas', fn ($q) => $q->vigentes());
        }

        if (! empty($filtros['buscar'])) {
            foreach (preg_split('/\s+/', mb_strtolower(trim($filtros['buscar']))) as $palabra) {
                $termino = "%{$palabra}%";
                $query->where(function ($q) use ($termino) {
                    $q->whereRaw('LOWER(nombres) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(apellidos) LIKE ?', [$termino])
                        ->orWhere('numero_documento', 'like', $termino)
                        ->orWhereRaw('LOWER(registro_profesional) LIKE ?', [$termino]);
                });
            }
        }

        $paginador = $query->orderBy('apellidos')->orderBy('nombres')->paginate(max(1, min($porPagina, 100)));
        $paginador->getCollection()->each(fn (Especialista $e) => $this->resumirAgendas($e));

        return $paginador;
    }

    /**
     * Cifras del talento humano para el tablero.
     *
     * @return array{activos: int, horas_semana: float, sin_agenda: int, ausentes_hoy: int}
     */
    public function resumen(): array
    {
        $hoy = now()->toDateString();
        $activos = Especialista::where('activo', true);

        return [
            'activos' => (clone $activos)->count(),
            'horas_semana' => round(
                Agenda::vigentes()->whereHas('especialista', fn ($q) => $q->where('activo', true))->get()->sum('horas_semana'),
                1
            ),
            'sin_agenda' => (clone $activos)->whereDoesntHave('agendas', fn ($q) => $q->vigentes())->count(),
            'ausentes_hoy' => Ausencia::whereDate('fecha_inicio', '<=', $hoy)->whereDate('fecha_fin', '>=', $hoy)
                ->whereHas('especialista', fn ($q) => $q->where('activo', true))
                ->distinct('especialista_id')->count('especialista_id'),
        ];
    }

    /**
     * Detalle con agendas (todas, no solo las vigentes) y novedades desde hace 30 días.
     *
     * @throws ModelNotFoundException
     */
    public function obtenerPorId(int $id, bool $incluirEliminados = false): Especialista
    {
        $query = Especialista::with(self::RELACIONES_LISTADO + [
            'agendas' => fn ($q) => $q->with(['sede:id,nombre,prestador_id,dias_atencion,hora_apertura,hora_cierre', 'sede.prestador:id,razon_social,nombre_comercial', 'especialidad:id,nombre'])
                ->orderByDesc('activo')->orderBy('vigente_desde'),
            'ausencias' => fn ($q) => $q->whereDate('fecha_fin', '>=', now()->subDays(30)->toDateString())->orderBy('fecha_inicio'),
        ]);

        if ($incluirEliminados) {
            $query->withTrashed();
        }

        $especialista = $query->find($id);
        if (! $especialista) {
            throw new ModelNotFoundException("No se encontró el especialista con ID: {$id}");
        }

        return $this->resumirAgendas($especialista);
    }

    public function crear(array $datos): Especialista
    {
        return DB::transaction(function () use ($datos) {
            $especialidades = $datos['especialidad_ids'];
            unset($datos['especialidad_ids']);
            $datos['activo'] = $datos['activo'] ?? true;

            $especialista = Especialista::create($datos);
            $especialista->especialidades()->sync($especialidades);

            return $this->obtenerPorId($especialista->id);
        });
    }

    /**
     * @throws ValidationException si se retira una especialidad que el profesional tiene en agenda vigente.
     */
    public function actualizar(int $id, array $datos): Especialista
    {
        return DB::transaction(function () use ($id, $datos) {
            $especialista = $this->buscar($id);

            if (array_key_exists('especialidad_ids', $datos)) {
                $enUso = $especialista->agendas()->vigentes()->whereNotIn('especialidad_id', $datos['especialidad_ids'])
                    ->with('especialidad:id,nombre')->get()->pluck('especialidad.nombre')->unique();
                if ($enUso->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'especialidad_ids' => ['El profesional tiene agenda vigente de '.$enUso->join(', ', ' y ').'. Retira o termina esas franjas primero.'],
                    ]);
                }
                $especialista->especialidades()->sync($datos['especialidad_ids']);
                unset($datos['especialidad_ids']);
            }

            $especialista->update($datos);

            return $this->obtenerPorId($id);
        });
    }

    /**
     * Elimina lógicamente al especialista junto con sus franjas de agenda.
     */
    public function eliminar(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $especialista = $this->buscar($id);
            $especialista->agendas()->get()->each->delete();

            return (bool) $especialista->delete();
        });
    }

    /**
     * Restaura al especialista y las franjas que se eliminaron con él.
     *
     * @throws ValidationException si otro especialista activo ya usa el documento.
     */
    public function restaurar(int $id): Especialista
    {
        return DB::transaction(function () use ($id) {
            $especialista = $this->buscar($id, true);

            if ($especialista->trashed()) {
                $duplicado = Especialista::where('tipo_documento_id', $especialista->tipo_documento_id)
                    ->where('numero_documento', $especialista->numero_documento)->exists();
                if ($duplicado) {
                    throw ValidationException::withMessages([
                        'numero_documento' => ['Ya existe otro especialista activo con este documento.'],
                    ]);
                }

                $eliminadoEl = $especialista->deleted_at;
                $especialista->restore();
                $especialista->agendas()->onlyTrashed()->where('deleted_at', '>=', $eliminadoEl)->get()->each->restore();
            }

            return $this->obtenerPorId($id);
        });
    }

    /**
     * Modelo sin relaciones ni atributos calculados, para modificarlo.
     *
     * @throws ModelNotFoundException
     */
    private function buscar(int $id, bool $incluirEliminados = false): Especialista
    {
        $especialista = $incluirEliminados ? Especialista::withTrashed()->find($id) : Especialista::find($id);
        if (! $especialista) {
            throw new ModelNotFoundException("No se encontró el especialista con ID: {$id}");
        }

        return $especialista;
    }

    /**
     * Agrega las horas semanales y las sedes de la agenda vigente.
     */
    private function resumirAgendas(Especialista $especialista): Especialista
    {
        $hoy = now()->toDateString();
        $vigentes = $especialista->agendas->filter(
            fn (Agenda $a) => $a->activo && ($a->vigente_hasta === null || $a->vigente_hasta->toDateString() >= $hoy)
        );

        $especialista->setAttribute('horas_semana', round($vigentes->sum('horas_semana'), 1));
        $especialista->setAttribute('sedes', $vigentes->pluck('sede')->filter()->unique('id')->values()->map->only(['id', 'nombre']));

        return $especialista;
    }
}
