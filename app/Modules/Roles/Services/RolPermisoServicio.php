<?php

namespace App\Modules\Roles\Services;

use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolPermisoServicio
{
    /**
     * Obtiene todos los roles disponibles con sus permisos asociados.
     */
    public function listarRoles(): Collection
    {
        return Role::with('permissions')->orderBy('name', 'asc')->get();
    }

    /**
     * Obtiene todos los permisos disponibles.
     */
    public function listarPermisos(): Collection
    {
        return Permission::orderBy('name', 'asc')->get();
    }

    /**
     * Obtiene los permisos agrupados por prefijo de módulo.
     */
    public function listarPermisosAgrupados(): array
    {
        $permisos = Permission::orderBy('name', 'asc')->get();
        $agrupados = [];

        foreach ($permisos as $permiso) {
            $partes = explode('.', $permiso->name);
            $modulo = count($partes) > 1 ? $partes[0] : 'general';
            $agrupados[$modulo][] = $permiso;
        }

        return $agrupados;
    }
}
