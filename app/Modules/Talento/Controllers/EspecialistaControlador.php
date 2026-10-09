<?php

namespace App\Modules\Talento\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Common\Traits\RespuestaApiTrait;
use App\Modules\Talento\Requests\GuardarEspecialistaRequest;
use App\Modules\Talento\Requests\ImportarEspecialistasRequest;
use App\Modules\Talento\Services\EspecialistaServicio;
use App\Modules\Talento\Services\ImportadorEspecialistas;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class EspecialistaControlador extends Controller
{
    use RespuestaApiTrait;

    public function __construct(
        protected EspecialistaServicio $especialistaServicio,
        protected ImportadorEspecialistas $importador
    ) {}

    /**
     * Lista especialistas (filtros: búsqueda, estado, especialidad, sede, sin agenda).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $especialistas = $this->especialistaServicio->listar(
                $request->only(['buscar', 'activo', 'especialidad_id', 'sede_id', 'sin_agenda', 'solo_eliminados']),
                (int) $request->input('por_pagina', 20)
            );

            return $this->respuestaPaginada($especialistas, 'Listado de especialistas obtenido exitosamente.');
        } catch (Throwable $e) {
            return $this->respuestaError('Error al obtener los especialistas.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Cifras del talento humano: activos, horas de agenda, sin agenda y ausentes hoy.
     */
    public function resumen(): JsonResponse
    {
        return $this->ejecutar(fn () => $this->especialistaServicio->resumen(), 'Resumen de especialistas obtenido exitosamente.', 'Error al obtener el resumen.');
    }

    public function show(int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->especialistaServicio->obtenerPorId((int) $id, true), 'Detalle del especialista obtenido exitosamente.', 'Error al consultar el especialista.');
    }

    public function store(GuardarEspecialistaRequest $request): JsonResponse
    {
        return $this->ejecutar(fn () => $this->especialistaServicio->crear($request->validated()), 'Especialista creado exitosamente.', 'Error al registrar el especialista.', 201);
    }

    public function update(GuardarEspecialistaRequest $request, int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->especialistaServicio->actualizar((int) $id, $request->validated()), 'Especialista actualizado exitosamente.', 'Error al actualizar el especialista.');
    }

    public function destroy(int|string $id): JsonResponse
    {
        return $this->ejecutar(function () use ($id) {
            $this->especialistaServicio->eliminar((int) $id);

            return null;
        }, 'Especialista eliminado exitosamente.', 'Error al eliminar el especialista.');
    }

    public function restore(int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->especialistaServicio->restaurar((int) $id), 'Especialista restaurado exitosamente.', 'Error al restaurar el especialista.');
    }

    /**
     * Cargue masivo desde CSV. Con `simular=1` solo valida y devuelve el reporte.
     */
    public function importar(ImportarEspecialistasRequest $request): JsonResponse
    {
        $simular = $request->boolean('simular');
        $mensaje = $simular ? 'Archivo revisado.' : 'Cargue de especialistas terminado.';

        return $this->ejecutar(
            fn () => $this->importador->importar($request->file('archivo')->getRealPath(), $simular),
            $mensaje,
            'Error al procesar el archivo de especialistas.'
        );
    }

    /**
     * Ejecuta una operación y la traduce al formato estándar de respuesta.
     */
    private function ejecutar(callable $operacion, string $mensaje, string $mensajeError, int $codigo = 200): JsonResponse
    {
        try {
            return $this->respuestaExito($operacion(), $mensaje, $codigo);
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (ValidationException $e) {
            return $this->respuestaError('Los datos enviados no son válidos.', 422, $e->errors());
        } catch (Throwable $e) {
            return $this->respuestaError($mensajeError, 500, config('app.debug') ? $e->getMessage() : null);
        }
    }
}
