<?php

namespace App\Modules\Servicios\Services;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Servicios\Models\PortafolioItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PortafolioServicio
{
    private const RELACIONES = [
        'cups:id,codigo,nombre,seccion,habilitado,es_quirurgico',
        'cups.especialidades:id,codigo,nombre',
        'sede:id,prestador_id,numero_sede,nombre,activo',
        'sede.prestador:id,razon_social,nombre_comercial',
    ];

    /**
     * Lista el portafolio con filtros por sede, prestador, especialidad o búsqueda de CUPS.
     */
    public function listar(array $filtros = [], int $porPagina = 25): LengthAwarePaginator
    {
        $query = PortafolioItem::query()->with(self::RELACIONES);

        if (! empty($filtros['sede_id'])) {
            $query->where('sede_id', (int) $filtros['sede_id']);
        }

        if (! empty($filtros['prestador_id'])) {
            $query->whereHas('sede', fn ($q) => $q->where('prestador_id', (int) $filtros['prestador_id']));
        }

        if (isset($filtros['activo']) && $filtros['activo'] !== '') {
            $query->where('activo', filter_var($filtros['activo'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filtros['especialidad_id'])) {
            $query->whereHas('cups.especialidades', fn ($q) => $q->where('especialidades.id', (int) $filtros['especialidad_id']));
        }

        if (! empty($filtros['sin_especialidad'])) {
            $query->whereDoesntHave('cups.especialidades');
        }

        if (! empty($filtros['buscar'])) {
            $termino = trim($filtros['buscar']);
            $query->whereHas('cups', function ($q) use ($termino) {
                if (ctype_digit($termino)) {
                    $q->where('codigo', 'like', "{$termino}%");
                } else {
                    foreach (preg_split('/\s+/', Str::upper(Str::ascii($termino))) as $palabra) {
                        $q->where('nombre_normalizado', 'like', "%{$palabra}%");
                    }
                }
            });
        }

        $query->orderBy(Cups::select('codigo')->whereColumn('cups.id', 'portafolio_servicios.cups_id'))->orderBy('sede_id');

        return $query->paginate(max(1, min($porPagina, 100)));
    }

    /**
     * Agrega varios CUPS a varias sedes. Los que ya existían en una sede se omiten.
     *
     * @return array{creados: int, existentes: int}
     */
    public function agregar(array $sedeIds, array $cupsIds, int $duracion): array
    {
        return DB::transaction(function () use ($sedeIds, $cupsIds, $duracion) {
            $existentes = PortafolioItem::whereIn('sede_id', $sedeIds)->whereIn('cups_id', $cupsIds)
                ->get(['sede_id', 'cups_id'])
                ->map(fn ($i) => "{$i->sede_id}:{$i->cups_id}")
                ->flip();

            $ahora = now();
            $nuevos = [];
            foreach ($sedeIds as $sedeId) {
                foreach ($cupsIds as $cupsId) {
                    if (! isset($existentes["{$sedeId}:{$cupsId}"])) {
                        $nuevos[] = [
                            'sede_id' => $sedeId,
                            'cups_id' => $cupsId,
                            'duracion_minutos' => $duracion,
                            'activo' => true,
                            'created_at' => $ahora,
                            'updated_at' => $ahora,
                        ];
                    }
                }
            }

            foreach (array_chunk($nuevos, 500) as $lote) {
                PortafolioItem::insert($lote);
            }

            return ['creados' => count($nuevos), 'existentes' => $existentes->count()];
        });
    }

    /**
     * @throws ModelNotFoundException
     */
    public function obtenerPorId(int $id): PortafolioItem
    {
        $item = PortafolioItem::with(self::RELACIONES)->find($id);
        if (! $item) {
            throw new ModelNotFoundException("No se encontró el servicio del portafolio con ID: {$id}");
        }

        return $item;
    }

    public function actualizar(int $id, array $datos): PortafolioItem
    {
        $this->obtenerPorId($id)->update($datos);

        return $this->obtenerPorId($id);
    }

    public function eliminar(int $id): bool
    {
        return (bool) $this->obtenerPorId($id)->delete();
    }
}
