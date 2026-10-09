<?php

namespace App\Modules\Contratacion\Controllers;

use App\Modules\Common\Controllers\ControladorBase;
use App\Modules\Contratacion\Requests\GuardarEntidadRequest;
use App\Modules\Contratacion\Services\EntidadServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntidadControlador extends ControladorBase
{
    public function __construct(
        protected EntidadServicio $entidadServicio
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->entidadServicio->listar($request->only(['buscar', 'activo', 'tipo', 'regimen_id', 'solo_eliminados']), (int) $request->input('por_pagina', 20)),
            'Listado de entidades obtenido exitosamente.',
            'Error al obtener las entidades.'
        );
    }

    public function show(int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->entidadServicio->obtenerPorId((int) $id, true), 'Detalle de la entidad obtenido exitosamente.', 'Error al consultar la entidad.');
    }

    public function store(GuardarEntidadRequest $request): JsonResponse
    {
        return $this->ejecutar(fn () => $this->entidadServicio->crear($request->validated()), 'Entidad creada exitosamente.', 'Error al registrar la entidad.', 201);
    }

    public function update(GuardarEntidadRequest $request, int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->entidadServicio->actualizar((int) $id, $request->validated()), 'Entidad actualizada exitosamente.', 'Error al actualizar la entidad.');
    }

    public function destroy(int|string $id): JsonResponse
    {
        return $this->ejecutar(function () use ($id) {
            $this->entidadServicio->eliminar((int) $id);

            return null;
        }, 'Entidad eliminada exitosamente.', 'Error al eliminar la entidad.');
    }

    public function restore(int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->entidadServicio->restaurar((int) $id), 'Entidad restaurada exitosamente.', 'Error al restaurar la entidad.');
    }
}
