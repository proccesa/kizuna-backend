<?php

namespace App\Modules\Integracion\Controllers;

use App\Modules\Common\Controllers\ControladorBase;
use App\Modules\Integracion\Models\ClienteIntegracion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administración de los sistemas externos y sus tokens.
 */
class ClienteIntegracionControlador extends ControladorBase
{
    public function index(): JsonResponse
    {
        return $this->ejecutar(
            fn () => ClienteIntegracion::with('tokens:id,tokenable_id,tokenable_type,name,abilities,last_used_at,created_at')->orderBy('nombre')->get(),
            'Clientes de integración.',
            'Error al obtener los clientes de integración.'
        );
    }

    /**
     * Crea el cliente y devuelve su token. El token solo se muestra esta vez.
     */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9 _.-]+$/', 'unique:clientes_integracion,nombre'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'permisos' => ['required', 'array', 'min:1'],
            'permisos.*' => [Rule::in(ClienteIntegracion::PERMISOS)],
        ], [
            'nombre.required' => 'Indica el nombre del sistema.',
            'nombre.unique' => 'Ya existe un cliente con este nombre.',
            'nombre.regex' => 'El nombre admite letras, números, espacios, guiones y puntos.',
            'permisos.required' => 'Elige al menos un permiso.',
        ]);

        return $this->ejecutar(function () use ($datos) {
            $cliente = ClienteIntegracion::create(['nombre' => $datos['nombre'], 'descripcion' => $datos['descripcion'] ?? null, 'activo' => true]);

            return ['cliente' => $cliente, 'token' => $cliente->createToken('principal', $datos['permisos'])->plainTextToken];
        }, 'Cliente de integración creado. Copia el token ahora: no se volverá a mostrar.', 'Error al crear el cliente.', 201);
    }

    /**
     * Revoca los tokens y emite uno nuevo con los permisos indicados.
     */
    public function regenerar(Request $request, int|string $id): JsonResponse
    {
        $datos = $request->validate(['permisos' => ['required', 'array', 'min:1'], 'permisos.*' => [Rule::in(ClienteIntegracion::PERMISOS)]]);

        return $this->ejecutar(function () use ($id, $datos) {
            $cliente = $this->buscar((int) $id);
            $cliente->tokens()->delete();

            return ['cliente' => $cliente, 'token' => $cliente->createToken('principal', $datos['permisos'])->plainTextToken];
        }, 'Token regenerado. El anterior dejó de funcionar.', 'Error al regenerar el token.');
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        $datos = $request->validate(['activo' => ['sometimes', 'boolean'], 'descripcion' => ['sometimes', 'nullable', 'string', 'max:255']]);

        return $this->ejecutar(function () use ($id, $datos) {
            $cliente = $this->buscar((int) $id);
            $cliente->update($datos);

            return $cliente->load('tokens:id,tokenable_id,tokenable_type,name,abilities,last_used_at,created_at');
        }, 'Cliente de integración actualizado.', 'Error al actualizar el cliente.');
    }

    public function destroy(int|string $id): JsonResponse
    {
        return $this->ejecutar(function () use ($id) {
            $cliente = $this->buscar((int) $id);
            $cliente->tokens()->delete();
            $cliente->delete();

            return null;
        }, 'Cliente de integración eliminado y sus tokens revocados.', 'Error al eliminar el cliente.');
    }

    private function buscar(int $id): ClienteIntegracion
    {
        $cliente = ClienteIntegracion::find($id);
        if (! $cliente) {
            throw new ModelNotFoundException("No se encontró el cliente de integración con ID: {$id}");
        }

        return $cliente;
    }
}
