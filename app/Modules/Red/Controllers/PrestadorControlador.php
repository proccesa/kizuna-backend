<?php

namespace App\Modules\Red\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Common\Traits\RespuestaApiTrait;
use App\Modules\Red\Requests\GuardarPrestadorRequest;
use App\Modules\Red\Services\PrestadorServicio;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PrestadorControlador extends Controller
{
    use RespuestaApiTrait;

    public function __construct(
        protected PrestadorServicio $prestadorServicio
    ) {}

    /**
     * Lista los prestadores con sus sedes.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $prestadores = $this->prestadorServicio->listar(
                $request->only(['buscar', 'activo', 'naturaleza', 'incluir_eliminados', 'solo_eliminados', 'ordenar_por', 'orden_direccion']),
                (int) $request->input('por_pagina', 15)
            );

            return $this->respuestaPaginada($prestadores, 'Listado de prestadores obtenido exitosamente.');
        } catch (Throwable $e) {
            return $this->respuestaError('Error al obtener los prestadores.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Detalle de un prestador con sus sedes.
     */
    public function show(int|string $id): JsonResponse
    {
        try {
            return $this->respuestaExito(
                $this->prestadorServicio->obtenerPorId((int) $id, true),
                'Detalle del prestador obtenido exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError('Error al consultar el prestador.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Registra un prestador.
     */
    public function store(GuardarPrestadorRequest $request): JsonResponse
    {
        try {
            return $this->respuestaExito(
                $this->prestadorServicio->crear($request->validated()),
                'Prestador creado exitosamente.',
                201
            );
        } catch (Throwable $e) {
            return $this->respuestaError('Error al registrar el prestador.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Actualiza un prestador.
     */
    public function update(GuardarPrestadorRequest $request, int|string $id): JsonResponse
    {
        try {
            return $this->respuestaExito(
                $this->prestadorServicio->actualizar((int) $id, $request->validated()),
                'Prestador actualizado exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError('Error al actualizar el prestador.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Elimina lógicamente el prestador y sus sedes.
     */
    public function destroy(int|string $id): JsonResponse
    {
        try {
            $this->prestadorServicio->eliminar((int) $id);

            return $this->respuestaExito(null, 'Prestador y sus sedes eliminados exitosamente.');
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError('Error al eliminar el prestador.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Restaura el prestador y las sedes eliminadas con él.
     */
    public function restore(int|string $id): JsonResponse
    {
        try {
            return $this->respuestaExito(
                $this->prestadorServicio->restaurar((int) $id),
                'Prestador restaurado exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError('Error al restaurar el prestador.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }
}
