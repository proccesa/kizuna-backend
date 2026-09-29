<?php

namespace App\Modules\Users\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Common\Traits\RespuestaApiTrait;
use App\Modules\Users\Requests\ActualizarOperadorRequest;
use App\Modules\Users\Requests\CrearOperadorRequest;
use App\Modules\Users\Services\OperadorServicio;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class OperadorControlador extends Controller
{
    use RespuestaApiTrait;

    public function __construct(
        protected OperadorServicio $operadorServicio
    ) {}

    /**
     * Lista los operadores con filtros y paginación.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filtros = $request->only([
                'buscar',
                'activo',
                'tipo_documento_id',
                'incluir_eliminados',
                'solo_eliminados',
                'ordenar_por',
                'orden_direccion',
            ]);

            $porPagina = (int) $request->input('por_pagina', 15);
            $operadores = $this->operadorServicio->listar($filtros, $porPagina);

            return $this->respuestaPaginada(
                $operadores,
                'Listado de operadores obtenido exitosamente.'
            );
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al obtener los operadores.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Obtiene el detalle de un operador.
     */
    public function show(int|string $id): JsonResponse
    {
        try {
            $operador = $this->operadorServicio->obtenerPorId((int) $id, true);

            return $this->respuestaExito(
                $operador,
                'Detalle del operador obtenido exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al consultar el operador.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Crea un nuevo operador.
     */
    public function store(CrearOperadorRequest $request): JsonResponse
    {
        try {
            $operador = $this->operadorServicio->crear($request->validated());

            return $this->respuestaExito(
                $operador,
                'Operador registrado exitosamente.',
                201
            );
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al crear el operador.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Actualiza un operador.
     */
    public function update(ActualizarOperadorRequest $request, int|string $id): JsonResponse
    {
        try {
            $operador = $this->operadorServicio->actualizar((int) $id, $request->validated());

            return $this->respuestaExito(
                $operador,
                'Operador actualizado exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al actualizar el operador.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Elimina lógicamente a un operador.
     */
    public function destroy(int|string $id): JsonResponse
    {
        try {
            $this->operadorServicio->eliminar((int) $id);

            return $this->respuestaExito(
                null,
                'Operador eliminado exitosamente (Soft Delete).'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al eliminar el operador.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Restaura un operador eliminado.
     */
    public function restore(int|string $id): JsonResponse
    {
        try {
            $this->operadorServicio->restaurar((int) $id);

            return $this->respuestaExito(
                null,
                'Operador restaurado exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al restaurar el operador.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }
}
