<?php

namespace App\Modules\Servicios\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Common\Traits\RespuestaApiTrait;
use App\Modules\Servicios\Requests\GuardarEspecialidadRequest;
use App\Modules\Servicios\Services\EspecialidadServicio;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class EspecialidadControlador extends Controller
{
    use RespuestaApiTrait;

    public function __construct(
        protected EspecialidadServicio $especialidadServicio
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            return $this->respuestaExito(
                $this->especialidadServicio->listar($request->only(['buscar', 'activo', 'solo_eliminados'])),
                'Listado de especialidades obtenido exitosamente.'
            );
        } catch (Throwable $e) {
            return $this->respuestaError('Error al obtener las especialidades.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    public function show(int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->especialidadServicio->obtenerPorId((int) $id, true), 'Detalle de la especialidad obtenido exitosamente.', 'Error al consultar la especialidad.');
    }

    public function store(GuardarEspecialidadRequest $request): JsonResponse
    {
        return $this->ejecutar(fn () => $this->especialidadServicio->crear($request->validated()), 'Especialidad creada exitosamente.', 'Error al registrar la especialidad.', 201);
    }

    public function update(GuardarEspecialidadRequest $request, int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->especialidadServicio->actualizar((int) $id, $request->validated()), 'Especialidad actualizada exitosamente.', 'Error al actualizar la especialidad.');
    }

    public function destroy(int|string $id): JsonResponse
    {
        return $this->ejecutar(function () use ($id) {
            $this->especialidadServicio->eliminar((int) $id);

            return null;
        }, 'Especialidad eliminada exitosamente.', 'Error al eliminar la especialidad.');
    }

    public function restore(int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->especialidadServicio->restaurar((int) $id), 'Especialidad restaurada exitosamente.', 'Error al restaurar la especialidad.');
    }

    /**
     * CUPS que atiende la especialidad.
     */
    public function cups(int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->especialidadServicio->cupsDe((int) $id), 'CUPS de la especialidad obtenidos exitosamente.', 'Error al consultar los CUPS de la especialidad.');
    }

    public function asignarCups(int|string $id, int|string $cupsId): JsonResponse
    {
        return $this->ejecutar(fn () => $this->especialidadServicio->asignarCups((int) $id, (int) $cupsId), 'CUPS asignado a la especialidad.', 'Error al asignar el CUPS.');
    }

    public function quitarCups(int|string $id, int|string $cupsId): JsonResponse
    {
        return $this->ejecutar(fn () => $this->especialidadServicio->quitarCups((int) $id, (int) $cupsId), 'CUPS retirado de la especialidad.', 'Error al retirar el CUPS.');
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
        } catch (Throwable $e) {
            return $this->respuestaError($mensajeError, 500, config('app.debug') ? $e->getMessage() : null);
        }
    }
}
