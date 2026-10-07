<?php

namespace App\Modules\Red\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Common\Traits\RespuestaApiTrait;
use App\Modules\Red\Requests\GuardarSedeRequest;
use App\Modules\Red\Services\SedeServicio;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class SedeControlador extends Controller
{
    use RespuestaApiTrait;

    public function __construct(
        protected SedeServicio $sedeServicio
    ) {}

    /**
     * Lista las sedes de toda la red (filtros: prestador, municipio, estado, búsqueda).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $sedes = $this->sedeServicio->listar(
                $request->only(['buscar', 'activo', 'prestador_id', 'municipio_id', 'solo_eliminados']),
                (int) $request->input('por_pagina', 50)
            );

            return $this->respuestaPaginada($sedes, 'Listado de sedes obtenido exitosamente.');
        } catch (Throwable $e) {
            return $this->respuestaError('Error al obtener las sedes.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Sedes de un prestador.
     */
    public function indexDePrestador(int|string $id): JsonResponse
    {
        try {
            return $this->respuestaExito(
                $this->sedeServicio->listarDePrestador((int) $id),
                'Sedes del prestador obtenidas exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError('Error al obtener las sedes del prestador.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Detalle de una sede.
     */
    public function show(int|string $sedeId): JsonResponse
    {
        try {
            return $this->respuestaExito($this->sedeServicio->obtenerPorId((int) $sedeId, true), 'Detalle de la sede obtenido exitosamente.');
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError('Error al consultar la sede.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Registra una sede en un prestador.
     */
    public function store(GuardarSedeRequest $request, int|string $id): JsonResponse
    {
        try {
            return $this->respuestaExito(
                $this->sedeServicio->crear((int) $id, $request->validated()),
                'Sede creada exitosamente.',
                201
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError('Error al registrar la sede.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Actualiza una sede.
     */
    public function update(GuardarSedeRequest $request, int|string $sedeId): JsonResponse
    {
        try {
            return $this->respuestaExito(
                $this->sedeServicio->actualizar((int) $sedeId, $request->validated()),
                'Sede actualizada exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError('Error al actualizar la sede.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Elimina lógicamente una sede.
     */
    public function destroy(int|string $sedeId): JsonResponse
    {
        try {
            $this->sedeServicio->eliminar((int) $sedeId);

            return $this->respuestaExito(null, 'Sede eliminada exitosamente.');
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError('Error al eliminar la sede.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Restaura una sede eliminada.
     */
    public function restore(int|string $sedeId): JsonResponse
    {
        try {
            return $this->respuestaExito($this->sedeServicio->restaurar((int) $sedeId), 'Sede restaurada exitosamente.');
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (ValidationException $e) {
            return $this->respuestaError('No es posible restaurar la sede.', 422, $e->errors());
        } catch (Throwable $e) {
            return $this->respuestaError('Error al restaurar la sede.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }
}
