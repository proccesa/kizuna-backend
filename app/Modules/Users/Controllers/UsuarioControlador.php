<?php

namespace App\Modules\Users\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Common\Traits\RespuestaApiTrait;
use App\Modules\Users\Requests\ActualizarUsuarioRequest;
use App\Modules\Users\Requests\CrearUsuarioRequest;
use App\Modules\Users\Services\UsuarioServicio;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class UsuarioControlador extends Controller
{
    use RespuestaApiTrait;

    public function __construct(
        protected UsuarioServicio $usuarioServicio
    ) {}

    /**
     * Lista los usuarios con filtros y paginación.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filtros = $request->only([
                'buscar',
                'activo',
                'rol',
                'incluir_eliminados',
                'solo_eliminados',
                'ordenar_por',
                'orden_direccion',
            ]);

            $porPagina = (int) $request->input('por_pagina', 15);
            $usuarios = $this->usuarioServicio->listar($filtros, $porPagina);

            return $this->respuestaPaginada(
                $usuarios,
                'Listado de usuarios obtenido exitosamente.'
            );
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al obtener el listado de usuarios.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Obtiene el detalle de un usuario por su ID.
     */
    public function show(int|string $id): JsonResponse
    {
        try {
            $usuario = $this->usuarioServicio->obtenerPorId((int) $id, true);

            return $this->respuestaExito(
                $usuario,
                'Detalle del usuario obtenido exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al consultar el usuario.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Registra un nuevo usuario con operador opcional y roles.
     */
    public function store(CrearUsuarioRequest $request): JsonResponse
    {
        try {
            $datosUsuario = $request->only(['name', 'email', 'password', 'activo']);
            $datosOperador = $request->input('operador');
            $roles = $request->input('roles', []);

            $usuario = $this->usuarioServicio->crear($datosUsuario, $datosOperador, $roles);

            return $this->respuestaExito(
                $usuario,
                'Usuario creado exitosamente.',
                201
            );
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al registrar el usuario.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Actualiza un usuario existente.
     */
    public function update(ActualizarUsuarioRequest $request, int|string $id): JsonResponse
    {
        try {
            $datosUsuario = $request->only(['name', 'email', 'password', 'activo']);
            $datosOperador = $request->input('operador');
            $roles = $request->input('roles');

            $usuario = $this->usuarioServicio->actualizar((int) $id, $datosUsuario, $datosOperador, $roles);

            return $this->respuestaExito(
                $usuario,
                'Usuario actualizado exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al actualizar el usuario.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Elimina lógicamente a un usuario (SoftDelete).
     */
    public function destroy(int|string $id): JsonResponse
    {
        try {
            $this->usuarioServicio->eliminar((int) $id);

            return $this->respuestaExito(
                null,
                'Usuario eliminado exitosamente (Soft Delete).'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al eliminar el usuario.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Restaura un usuario previamente eliminado.
     */
    public function restore(int|string $id): JsonResponse
    {
        try {
            $this->usuarioServicio->restaurar((int) $id);

            return $this->respuestaExito(
                null,
                'Usuario restaurado exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al restaurar el usuario.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Cambia el estado activo/inactivo de un usuario.
     */
    public function toggleStatus(Request $request, int|string $id): JsonResponse
    {
        try {
            $request->validate([
                'activo' => ['required', 'boolean'],
            ]);

            $usuario = $this->usuarioServicio->cambiarEstado((int) $id, (bool) $request->input('activo'));

            return $this->respuestaExito(
                $usuario,
                'Estado del usuario actualizado exitosamente.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->respuestaNoEncontrado($e->getMessage());
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al cambiar el estado del usuario.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }
}
