<?php

namespace App\Modules\Servicios\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Common\Traits\RespuestaApiTrait;
use App\Modules\Servicios\Requests\ActualizarPortafolioRequest;
use App\Modules\Servicios\Requests\AgregarPortafolioRequest;
use App\Modules\Servicios\Services\PortafolioServicio;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PortafolioControlador extends Controller
{
    use RespuestaApiTrait;

    public function __construct(
        protected PortafolioServicio $portafolioServicio
    ) {}

    /**
     * Servicios del portafolio (filtros: sede, prestador, especialidad, sin especialidad, búsqueda).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $items = $this->portafolioServicio->listar(
                $request->only(['sede_id', 'prestador_id', 'especialidad_id', 'sin_especialidad', 'activo', 'buscar']),
                (int) $request->input('por_pagina', 25)
            );

            return $this->respuestaPaginada($items, 'Portafolio de servicios obtenido exitosamente.');
        } catch (Throwable $e) {
            return $this->respuestaError('Error al obtener el portafolio.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Agrega CUPS a una o varias sedes.
     */
    public function store(AgregarPortafolioRequest $request): JsonResponse
    {
        try {
            $resultado = $this->portafolioServicio->agregar(
                $request->validated('sede_ids'),
                $request->validated('cups_ids'),
                (int) $request->validated('duracion_minutos')
            );
            $mensaje = "{$resultado['creados']} servicios agregados al portafolio.";
            if ($resultado['existentes']) {
                $mensaje .= " {$resultado['existentes']} ya estaban y se omitieron.";
            }

            return $this->respuestaExito($resultado, $mensaje, 201);
        } catch (Throwable $e) {
            return $this->respuestaError('Error al agregar servicios al portafolio.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    public function update(ActualizarPortafolioRequest $request, int|string $id): JsonResponse
    {
        try {
            return $this->respuestaExito(
                $this->portafolioServicio->actualizar((int) $id, $request->validated()),
                'Servicio del portafolio actualizado exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError('Error al actualizar el servicio del portafolio.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    public function destroy(int|string $id): JsonResponse
    {
        try {
            $this->portafolioServicio->eliminar((int) $id);

            return $this->respuestaExito(null, 'Servicio retirado del portafolio.');
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError('Error al retirar el servicio del portafolio.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }
}
