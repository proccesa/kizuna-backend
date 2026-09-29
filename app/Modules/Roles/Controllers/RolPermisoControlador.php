<?php

namespace App\Modules\Roles\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Common\Traits\RespuestaApiTrait;
use App\Modules\Roles\Services\RolPermisoServicio;
use Illuminate\Http\JsonResponse;
use Throwable;

class RolPermisoControlador extends Controller
{
    use RespuestaApiTrait;

    public function __construct(
        protected RolPermisoServicio $rolPermisoServicio
    ) {}

    /**
     * Lista los roles disponibles con sus permisos asociados.
     */
    public function roles(): JsonResponse
    {
        try {
            $roles = $this->rolPermisoServicio->listarRoles();

            return $this->respuestaExito(
                $roles,
                'Listado de roles obtenido exitosamente.'
            );
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al consultar los roles.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Lista los permisos del sistema organizados por módulo.
     */
    public function permisos(): JsonResponse
    {
        try {
            $permisos = $this->rolPermisoServicio->listarPermisosAgrupados();

            return $this->respuestaExito(
                $permisos,
                'Catálogo de permisos obtenido exitosamente.'
            );
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al consultar los permisos.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }
}
