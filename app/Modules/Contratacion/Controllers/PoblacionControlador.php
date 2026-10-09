<?php

namespace App\Modules\Contratacion\Controllers;

use App\Modules\Common\Controllers\ControladorBase;
use App\Modules\Contratacion\Requests\CargarPoblacionRequest;
use App\Modules\Contratacion\Requests\GuardarPoblacionRequest;
use App\Modules\Contratacion\Services\ImportadorPoblacion;
use App\Modules\Contratacion\Services\PoblacionServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PoblacionControlador extends ControladorBase
{
    public function __construct(
        protected PoblacionServicio $poblacionServicio,
        protected ImportadorPoblacion $importador
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->poblacionServicio->listar(
                $request->only(['buscar', 'activo', 'contrato_id', 'entidad_id', 'sin_cargue_mes', 'solo_eliminados']),
                (int) $request->input('por_pagina', 20)
            ),
            'Listado de poblaciones obtenido exitosamente.',
            'Error al obtener las poblaciones.'
        );
    }

    public function resumen(): JsonResponse
    {
        return $this->ejecutar(fn () => $this->poblacionServicio->resumen(), 'Resumen de poblaciones obtenido exitosamente.', 'Error al obtener el resumen de poblaciones.');
    }

    public function show(int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->poblacionServicio->obtenerPorId((int) $id, true), 'Detalle de la población obtenido exitosamente.', 'Error al consultar la población.');
    }

    public function store(GuardarPoblacionRequest $request): JsonResponse
    {
        return $this->ejecutar(fn () => $this->poblacionServicio->crear($request->validated()), 'Población creada exitosamente.', 'Error al registrar la población.', 201);
    }

    public function update(GuardarPoblacionRequest $request, int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->poblacionServicio->actualizar((int) $id, $request->validated()), 'Población actualizada exitosamente.', 'Error al actualizar la población.');
    }

    public function destroy(int|string $id): JsonResponse
    {
        return $this->ejecutar(function () use ($id) {
            $this->poblacionServicio->eliminar((int) $id);

            return null;
        }, 'Población eliminada exitosamente.', 'Error al eliminar la población.');
    }

    public function restore(int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->poblacionServicio->restaurar((int) $id), 'Población restaurada exitosamente.', 'Error al restaurar la población.');
    }

    public function pacientes(Request $request, int|string $id): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->poblacionServicio->pacientes((int) $id, $request->only(['buscar', 'cohorte', 'incluir_retirados']), (int) $request->input('por_pagina', 25)),
            'Pacientes de la población obtenidos exitosamente.',
            'Error al obtener los pacientes.'
        );
    }

    /**
     * Cargue de pacientes desde CSV. Con `simular=1` solo valida y devuelve el reporte.
     */
    public function cargar(CargarPoblacionRequest $request, int|string $id): JsonResponse
    {
        $simular = $request->boolean('simular');

        return $this->ejecutar(function () use ($request, $id, $simular) {
            $archivo = $request->file('archivo');

            return $this->importador->importar(
                $this->poblacionServicio->buscar((int) $id),
                $archivo->getRealPath(),
                $archivo->getClientOriginalName(),
                $simular,
                $request->input('modo', 'REEMPLAZAR'),
                $request->user()?->id
            );
        }, $simular ? 'Archivo revisado.' : 'Cargue de la población terminado.', 'Error al procesar el archivo de la población.');
    }
}
