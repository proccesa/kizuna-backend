<?php

namespace App\Modules\Users\Services;

use App\Modules\Users\Models\Operador;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsuarioServicio
{
    /**
     * Lista los usuarios con filtros opcionales y paginación.
     */
    public function listar(array $filtros = [], int $porPagina = 15): LengthAwarePaginator
    {
        $query = User::with(['operador.tipoDocumento', 'roles']);

        // Filtro para registros eliminados
        if (! empty($filtros['solo_eliminados'])) {
            $query->onlyTrashed();
        } elseif (! empty($filtros['incluir_eliminados'])) {
            $query->withTrashed();
        }

        // Filtro por estado activo/inactivo
        if (isset($filtros['activo']) && $filtros['activo'] !== '') {
            $query->where('activo', filter_var($filtros['activo'], FILTER_VALIDATE_BOOLEAN));
        }

        // Filtro por rol
        if (! empty($filtros['rol'])) {
            $query->role($filtros['rol']);
        }

        // Filtro de búsqueda general (nombre, email, documento u operador)
        if (! empty($filtros['buscar'])) {
            $termino = '%' . trim($filtros['buscar']) . '%';
            $query->where(function ($q) use ($termino) {
                $q->where('name', 'ilike', $termino)
                    ->orWhere('email', 'ilike', $termino)
                    ->orWhereHas('operador', function ($qOp) use ($termino) {
                        $qOp->where('nombre', 'ilike', $termino)
                            ->orWhere('apellido', 'ilike', $termino)
                            ->orWhere('documento', 'ilike', $termino);
                    });
            });
        }

        $campoOrden = $filtros['ordenar_por'] ?? 'id';
        $direccionOrden = strtolower($filtros['orden_direccion'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($campoOrden, $direccionOrden)->paginate($porPagina);
    }

    /**
     * Obtiene un usuario por su ID con sus relaciones.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function obtenerPorId(int $id, bool $incluirEliminados = false): User
    {
        $query = User::with(['operador.tipoDocumento', 'roles.permissions']);

        if ($incluirEliminados) {
            $query->withTrashed();
        }

        $usuario = $query->find($id);

        if (! $usuario) {
            throw new ModelNotFoundException("No se encontró el usuario con ID: {$id}");
        }

        return $usuario;
    }

    /**
     * Crea un nuevo usuario y opcionalmente su información de operador y roles asociados.
     */
    public function crear(array $datosUsuario, ?array $datosOperador = null, array $roles = []): User
    {
        return DB::transaction(function () use ($datosUsuario, $datosOperador, $roles) {
            // Hashear contraseña si no viene hasheada
            if (isset($datosUsuario['password'])) {
                $datosUsuario['password'] = Hash::make($datosUsuario['password']);
            }

            $datosUsuario['activo'] = $datosUsuario['activo'] ?? true;

            /** @var User $usuario */
            $usuario = User::create($datosUsuario);

            // Crear y asociar operador si se suministran sus datos
            if (! empty($datosOperador)) {
                $datosOperador['user_id'] = $usuario->id;
                $datosOperador['activo'] = $datosOperador['activo'] ?? true;
                Operador::create($datosOperador);
            }

            // Asignar roles si se proporcionan
            if (! empty($roles)) {
                $usuario->syncRoles($roles);
            }

            return $usuario->fresh(['operador.tipoDocumento', 'roles.permissions']);
        });
    }

    /**
     * Actualiza la información de un usuario, su operador y roles.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function actualizar(int $id, array $datosUsuario, ?array $datosOperador = null, ?array $roles = null): User
    {
        return DB::transaction(function () use ($id, $datosUsuario, $datosOperador, $roles) {
            $usuario = $this->obtenerPorId($id);

            // Actualizar contraseña solo si se envió una nueva
            if (! empty($datosUsuario['password'])) {
                $datosUsuario['password'] = Hash::make($datosUsuario['password']);
            } else {
                unset($datosUsuario['password']);
            }

            $usuario->update($datosUsuario);

            // Actualizar o crear operador asociado
            if ($datosOperador !== null) {
                if ($usuario->operador) {
                    $usuario->operador->update($datosOperador);
                } else {
                    $datosOperador['user_id'] = $usuario->id;
                    $datosOperador['activo'] = $datosOperador['activo'] ?? true;
                    Operador::create($datosOperador);
                }
            }

            // Sincronizar roles si fueron pasados
            if ($roles !== null) {
                $usuario->syncRoles($roles);
            }

            return $usuario->fresh(['operador.tipoDocumento', 'roles.permissions']);
        });
    }

    /**
     * Elimina lógicamente (SoftDelete) un usuario y su operador asociado.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function eliminar(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $usuario = $this->obtenerPorId($id);

            if ($usuario->operador) {
                $usuario->operador->delete();
            }

            // Revocar tokens activos
            $usuario->tokens()->delete();

            return (bool) $usuario->delete();
        });
    }

    /**
     * Restaura un usuario previamente eliminado mediante SoftDelete.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function restaurar(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $usuario = $this->obtenerPorId($id, true);

            if ($usuario->trashed()) {
                $usuario->restore();
            }

            // Restaurar también su operador si existía
            $operador = Operador::withTrashed()->where('user_id', $id)->first();
            if ($operador && $operador->trashed()) {
                $operador->restore();
            }

            return true;
        });
    }

    /**
     * Cambia el estado activo/inactivo de un usuario.
     */
    public function cambiarEstado(int $id, bool $activo): User
    {
        $usuario = $this->obtenerPorId($id);
        $usuario->activo = $activo;
        $usuario->save();

        if (! $activo) {
            // Revocar tokens si se desactiva la cuenta
            $usuario->tokens()->delete();
        }

        return $usuario->fresh(['operador.tipoDocumento', 'roles']);
    }
}
