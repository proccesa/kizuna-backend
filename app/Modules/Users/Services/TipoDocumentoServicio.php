<?php

namespace App\Modules\Users\Services;

use App\Modules\Users\Models\TipoDocumento;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TipoDocumentoServicio
{
    /**
     * Obtiene los tipos de documento activos para listas desplegables.
     */
    public function listarActivos(): Collection
    {
        return TipoDocumento::activos()->orderBy('nombre', 'asc')->get();
    }

    /**
     * Obtiene todos los tipos de documento.
     */
    public function listarTodos(): Collection
    {
        return TipoDocumento::orderBy('nombre', 'asc')->get();
    }

    /**
     * Obtiene un tipo de documento por su ID.
     *
     * @throws ModelNotFoundException
     */
    public function obtenerPorId(int $id): TipoDocumento
    {
        $tipo = TipoDocumento::find($id);

        if (! $tipo) {
            throw new ModelNotFoundException("No se encontró el tipo de documento con ID: {$id}");
        }

        return $tipo;
    }
}
