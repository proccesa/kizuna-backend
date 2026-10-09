<?php

namespace App\Modules\Talento\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Common\Traits\RespuestaApiTrait;
use App\Modules\Talento\Requests\GuardarAgendaRequest;
use App\Modules\Talento\Requests\GuardarAusenciaRequest;
use App\Modules\Talento\Services\AgendaServicio;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class AgendaControlador extends Controller
{
    use RespuestaApiTrait;

    public function __construct(
        protected AgendaServicio $agendaServicio
    ) {}

    /**
     * Franjas vigentes de la red (filtros: sede, especialidad, especialista).
     */
    public function index(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->agendaServicio->listar($request->only(['sede_id', 'especialidad_id', 'especialista_id'])),
            'Agendas obtenidas exitosamente.',
            'Error al obtener las agendas.'
        );
    }

    public function store(GuardarAgendaRequest $request, int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->agendaServicio->crear((int) $id, $request->validated()), 'Franja de agenda creada exitosamente.', 'Error al registrar la franja.', 201);
    }

    public function update(GuardarAgendaRequest $request, int|string $agendaId): JsonResponse
    {
        return $this->ejecutar(fn () => $this->agendaServicio->actualizar((int) $agendaId, $request->validated()), 'Franja de agenda actualizada exitosamente.', 'Error al actualizar la franja.');
    }

    public function destroy(int|string $agendaId): JsonResponse
    {
        return $this->ejecutar(function () use ($agendaId) {
            $this->agendaServicio->eliminar((int) $agendaId);

            return null;
        }, 'Franja de agenda eliminada.', 'Error al eliminar la franja.');
    }

    public function storeAusencia(GuardarAusenciaRequest $request, int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->agendaServicio->crearAusencia((int) $id, $request->validated()), 'Novedad registrada exitosamente.', 'Error al registrar la novedad.', 201);
    }

    public function destroyAusencia(int|string $ausenciaId): JsonResponse
    {
        return $this->ejecutar(function () use ($ausenciaId) {
            $this->agendaServicio->eliminarAusencia((int) $ausenciaId);

            return null;
        }, 'Novedad eliminada.', 'Error al eliminar la novedad.');
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
