<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Services\AutenticacionServicio;
use App\Modules\Common\Traits\RespuestaApiTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class AutenticacionControlador extends Controller
{
    use RespuestaApiTrait;

    public function __construct(
        protected AutenticacionServicio $autenticacionServicio
    ) {}

    /**
     * Inicia sesión para un usuario y genera su token API.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $resultado = $this->autenticacionServicio->iniciarSesion(
                $request->input('email'),
                $request->input('password'),
                $request->input('dispositivo')
            );

            return $this->respuestaExito(
                $resultado,
                'Inicio de sesión exitoso.'
            );
        } catch (ValidationException $e) {
            return $this->respuestaError(
                $e->getMessage(),
                422,
                $e->errors()
            );
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Ocurrió un error al procesar el inicio de sesión.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Retorna los datos del usuario autenticado actualmente.
     */
    public function perfil(Request $request): JsonResponse
    {
        try {
            $perfil = $this->autenticacionServicio->obtenerPerfil($request->user());

            return $this->respuestaExito(
                $perfil,
                'Perfil de usuario obtenido con éxito.'
            );
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al consultar el perfil de usuario.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }

    /**
     * Cierra la sesión activa revocando el token.
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $this->autenticacionServicio->cerrarSesion($request->user());

            return $this->respuestaExito(
                null,
                'Sesión cerrada exitosamente.'
            );
        } catch (Throwable $e) {
            return $this->respuestaError(
                'Error al cerrar la sesión.',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
        }
    }
}
