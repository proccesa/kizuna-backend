<?php

namespace App\Modules\Common\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Common\Traits\RespuestaApiTrait;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Throwable;

abstract class ControladorBase extends Controller
{
    use RespuestaApiTrait;

    /**
     * Ejecuta una operación y la traduce al formato estándar de respuesta.
     */
    protected function ejecutar(callable $operacion, string $mensaje, string $mensajeError, int $codigo = 200): JsonResponse
    {
        try {
            $resultado = $operacion();

            return $resultado instanceof LengthAwarePaginator
                ? $this->respuestaPaginada($resultado, $mensaje)
                : $this->respuestaExito($resultado, $mensaje, $codigo);
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (ValidationException $e) {
            return $this->respuestaError('Los datos enviados no son válidos.', 422, $e->errors());
        } catch (Throwable $e) {
            return $this->respuestaError($mensajeError, 500, config('app.debug') ? $e->getMessage() : null);
        }
    }
}
