<?php

namespace App\Modules\Auth\Services;

use App\Modules\Users\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AutenticacionServicio
{
    /**
     * Autentica a un usuario y genera un token de acceso Sanctum.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function iniciarSesion(string $email, string $password, ?string $nombreDispositivo = null): array
    {
        $usuario = User::with(['operador.tipoDocumento', 'roles.permissions'])
            ->where('email', $email)
            ->first();

        if (! $usuario || ! Hash::check($password, $usuario->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        if (! $usuario->estaActivo()) {
            throw ValidationException::withMessages([
                'email' => ['La cuenta de usuario se encuentra inactiva. Contacte al administrador.'],
            ]);
        }

        // Nombre de identificación para el token
        $dispositivo = $nombreDispositivo ?: 'token_api';
        $token = $usuario->createToken($dispositivo)->plainTextToken;

        return [
            'token' => $token,
            'tipo_token' => 'Bearer',
            'usuario' => [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'email' => $usuario->email,
                'activo' => $usuario->activo,
                'operador' => $usuario->operador,
                'roles' => $usuario->obtenerRoles(),
                'permisos' => $usuario->obtenerPermisos(),
                'creado_el' => $usuario->created_at,
            ],
        ];
    }

    /**
     * Cierra la sesión activa revocando el token actual.
     */
    public function cerrarSesion(User $usuario): bool
    {
        if ($usuario->currentAccessToken()) {
            $usuario->currentAccessToken()->delete();

            return true;
        }

        return false;
    }

    /**
     * Cierra todas las sesiones activas del usuario.
     */
    public function cerrarTodasLasSesiones(User $usuario): bool
    {
        $usuario->tokens()->delete();

        return true;
    }

    /**
     * Obtiene los datos del perfil del usuario autenticado con sus roles y permisos.
     */
    public function obtenerPerfil(User $usuario): array
    {
        $usuario->load(['operador.tipoDocumento', 'roles.permissions']);

        return [
            'id' => $usuario->id,
            'name' => $usuario->name,
            'email' => $usuario->email,
            'activo' => $usuario->activo,
            'operador' => $usuario->operador,
            'roles' => $usuario->obtenerRoles(),
            'permisos' => $usuario->obtenerPermisos(),
            'creado_el' => $usuario->created_at,
        ];
    }
}
