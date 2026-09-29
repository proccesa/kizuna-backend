<?php

namespace App\Modules\Users\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Common\Traits\RespuestaApiTrait;
use App\Modules\Users\Services\TipoDocumentoServicio;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Throwable;

class TipoDocumentoControlador extends Controller
{
    use RespuestaApiTrait;

    public function __construct(
        protected TipoDocumentoServicio $tipoDocumentoServicio
    ) {}

    /**
     * Lista todos los tipos de documento activos para formularios.
     */
    public function index(): JsonResponse
    {
        try {
            $tipos = $this->tipoDocumentoServicio->listarActivos();

            return $this->respuestaExito(
                $tipos,
                'Catálogo de tipos de documento obtenido exitosamente.'
            );
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al consultar los tipos de documento.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Obtiene el detalle de un tipo de documento.
     */
    public function show(int|string $id): JsonResponse
    {
        try {
            $tipo = $this->tipoDocumentoServicio->obtenerPorId((int) $id);

            return $this->respuestaExito(
                $tipo,
                'Detalle del tipo de documento obtenido exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al consultar el tipo de documento.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }
}
