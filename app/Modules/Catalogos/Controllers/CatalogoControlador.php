<?php

namespace App\Modules\Catalogos\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogos\Services\CatalogoServicio;
use App\Modules\Common\Traits\RespuestaApiTrait;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CatalogoControlador extends Controller
{
    use RespuestaApiTrait;

    public function __construct(
        protected CatalogoServicio $catalogoServicio
    ) {}

    /**
     * Lista los departamentos (DIVIPOLA).
     */
    public function departamentos(): JsonResponse
    {
        try {
            return $this->respuestaExito(
                $this->catalogoServicio->listarDepartamentos(),
                'Catálogo de departamentos obtenido exitosamente.'
            );
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al consultar los departamentos.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Lista los municipios de un departamento.
     */
    public function municipiosPorDepartamento(int|string $id): JsonResponse
    {
        try {
            return $this->respuestaExito(
                $this->catalogoServicio->listarMunicipiosPorDepartamento((int) $id),
                'Municipios del departamento obtenidos exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al consultar los municipios del departamento.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Busca municipios por nombre o código DANE (paginado).
     */
    public function municipios(Request $request): JsonResponse
    {
        try {
            $municipios = $this->catalogoServicio->buscarMunicipios(
                $request->only(['buscar', 'departamento_id']),
                (int) $request->input('por_pagina', 20)
            );

            return $this->respuestaPaginada(
                $municipios,
                'Municipios obtenidos exitosamente.'
            );
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al consultar los municipios.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Obtiene el detalle de un municipio.
     */
    public function municipio(int|string $id): JsonResponse
    {
        try {
            return $this->respuestaExito(
                $this->catalogoServicio->obtenerMunicipio((int) $id),
                'Detalle del municipio obtenido exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al consultar el municipio.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Lista los regímenes de afiliación.
     */
    public function regimenes(): JsonResponse
    {
        try {
            return $this->respuestaExito(
                $this->catalogoServicio->listarRegimenes(),
                'Catálogo de regímenes obtenido exitosamente.'
            );
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al consultar los regímenes.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Lista las modalidades de contratación.
     */
    public function modalidadesContratacion(): JsonResponse
    {
        try {
            return $this->respuestaExito(
                $this->catalogoServicio->listarModalidadesContratacion(),
                'Catálogo de modalidades de contratación obtenido exitosamente.'
            );
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al consultar las modalidades de contratación.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Busca procedimientos del catálogo CUPS (paginado).
     */
    public function cups(Request $request): JsonResponse
    {
        try {
            $cups = $this->catalogoServicio->buscarCups(
                $request->only(['buscar', 'habilitado']),
                (int) $request->input('por_pagina', 20)
            );

            return $this->respuestaPaginada($cups, 'Catálogo CUPS obtenido exitosamente.');
        } catch (Throwable $e) {
            return $this->respuestaError('Error al consultar el catálogo CUPS.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Detalle de un procedimiento CUPS con sus especialidades.
     */
    public function cupsDetalle(int|string $id): JsonResponse
    {
        try {
            return $this->respuestaExito($this->catalogoServicio->obtenerCups((int) $id), 'Detalle del CUPS obtenido exitosamente.');
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError('Error al consultar el CUPS.', 500, config('app.debug') ? $e->getMessage() : null);
        }
    }
}
