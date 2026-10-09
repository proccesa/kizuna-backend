<?php

namespace App\Modules\Contratacion\Controllers;

use App\Modules\Common\Controllers\ControladorBase;
use App\Modules\Contratacion\Requests\ActualizarCupsContratoRequest;
use App\Modules\Contratacion\Requests\AgregarCupsContratoRequest;
use App\Modules\Contratacion\Requests\GuardarContratoRequest;
use App\Modules\Contratacion\Services\ContratoServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContratoControlador extends ControladorBase
{
    public function __construct(
        protected ContratoServicio $contratoServicio
    ) {}

    /**
     * Lista contratos (filtros: entidad, modalidad, régimen, estado, búsqueda).
     */
    public function index(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->contratoServicio->listar(
                $request->only(['buscar', 'entidad_id', 'modalidad_contratacion_id', 'regimen_id', 'estado', 'solo_eliminados']),
                (int) $request->input('por_pagina', 20)
            ),
            'Listado de contratos obtenido exitosamente.',
            'Error al obtener los contratos.'
        );
    }

    public function resumen(): JsonResponse
    {
        return $this->ejecutar(fn () => $this->contratoServicio->resumen(), 'Resumen de contratos obtenido exitosamente.', 'Error al obtener el resumen de contratos.');
    }

    public function show(int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->contratoServicio->obtenerPorId((int) $id, true), 'Detalle del contrato obtenido exitosamente.', 'Error al consultar el contrato.');
    }

    public function store(GuardarContratoRequest $request): JsonResponse
    {
        return $this->ejecutar(fn () => $this->contratoServicio->crear($request->validated()), 'Contrato creado exitosamente.', 'Error al registrar el contrato.', 201);
    }

    public function update(GuardarContratoRequest $request, int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->contratoServicio->actualizar((int) $id, $request->validated()), 'Contrato actualizado exitosamente.', 'Error al actualizar el contrato.');
    }

    public function destroy(int|string $id): JsonResponse
    {
        return $this->ejecutar(function () use ($id) {
            $this->contratoServicio->eliminar((int) $id);

            return null;
        }, 'Contrato eliminado exitosamente.', 'Error al eliminar el contrato.');
    }

    public function restore(int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->contratoServicio->restaurar((int) $id), 'Contrato restaurado exitosamente.', 'Error al restaurar el contrato.');
    }

    /**
     * CUPS pactados con su estado frente al portafolio de las sedes del contrato.
     */
    public function cups(Request $request, int|string $id): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->contratoServicio->cups((int) $id, $request->only(['buscar', 'sin_portafolio']), (int) $request->input('por_pagina', 25)),
            'CUPS del contrato obtenidos exitosamente.',
            'Error al obtener los CUPS del contrato.'
        );
    }

    public function agregarCups(AgregarCupsContratoRequest $request, int|string $id): JsonResponse
    {
        return $this->ejecutar(function () use ($request, $id) {
            return $this->contratoServicio->agregarCups((int) $id, $request->validated());
        }, 'CUPS agregados al contrato.', 'Error al agregar los CUPS.', 201);
    }

    public function actualizarCups(ActualizarCupsContratoRequest $request, int|string $id, int|string $cupsId): JsonResponse
    {
        return $this->ejecutar(fn () => $this->contratoServicio->actualizarCups((int) $id, (int) $cupsId, $request->validated()), 'CUPS del contrato actualizado.', 'Error al actualizar el CUPS.');
    }

    public function quitarCups(int|string $id, int|string $cupsId): JsonResponse
    {
        return $this->ejecutar(fn () => $this->contratoServicio->quitarCups((int) $id, (int) $cupsId), 'CUPS retirado del contrato.', 'Error al retirar el CUPS.');
    }
}
