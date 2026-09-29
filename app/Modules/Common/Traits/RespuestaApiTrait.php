<?php

namespace App\Modules\Common\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

trait RespuestaApiTrait
{
    /**
     * Retorna una respuesta exitosa en formato JSON.
     */
    public function respuestaExito(mixed $datos = null, string $mensaje = 'Operación realizada con éxito', int $codigo = 200): JsonResponse
    {
        return response()->json([
            'exito' => true,
            'mensaje' => $mensaje,
            'datos' => $datos,
        ], $codigo);
    }

    /**
     * Retorna una respuesta de error en formato JSON.
     */
    public function respuestaError(string $mensaje = 'Ha ocurrido un error en la operación', int $codigo = 400, mixed $errores = null): JsonResponse
    {
        return response()->json([
            'exito' => false,
            'mensaje' => $mensaje,
            'errores' => $errores,
        ], $codigo);
    }

    /**
     * Retorna una respuesta paginada formateada en JSON.
     */
    public function respuestaPaginada(LengthAwarePaginator $paginador, string $mensaje = 'Registros obtenidos exitosamente'): JsonResponse
    {
        return response()->json([
            'exito' => true,
            'mensaje' => $mensaje,
            'datos' => $paginador->items(),
            'paginacion' => [
                'total' => $paginador->total(),
                'por_pagina' => $paginador->perPage(),
                'pagina_actual' => $paginador->currentPage(),
                'total_paginas' => $paginador->lastPage(),
                'desde' => $paginador->firstItem(),
                'hasta' => $paginador->lastItem(),
            ],
        ], 200);
    }

    /**
     * Retorna una respuesta de recurso no encontrado (404).
     */
    public function respuestaNoEncontrado(string $mensaje = 'El recurso solicitado no fue encontrado'): JsonResponse
    {
        return $this->respuestaError($mensaje, 404);
    }

    /**
     * Retorna una respuesta de no autenticado (401).
     */
    public function respuestaNoAutenticado(string $mensaje = 'No autenticado. Por favor inicie sesión'): JsonResponse
    {
        return $this->respuestaError($mensaje, 401);
    }

    /**
     * Retorna una respuesta de acceso no autorizado (403).
     */
    public function respuestaProhibido(string $mensaje = 'Acceso denegado. No posee los permisos requeridos'): JsonResponse
    {
        return $this->respuestaError($mensaje, 403);
    }
}
