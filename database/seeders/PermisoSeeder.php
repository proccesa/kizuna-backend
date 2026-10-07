<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermisoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permisos = [
            // Módulo de Usuarios
            'usuarios.listar',
            'usuarios.crear',
            'usuarios.ver',
            'usuarios.editar',
            'usuarios.eliminar',
            'usuarios.restaurar',

            // Módulo de Operadores
            'operadores.listar',
            'operadores.crear',
            'operadores.ver',
            'operadores.editar',
            'operadores.eliminar',
            'operadores.restaurar',

            // Módulo de Roles y Permisos
            'roles.listar',
            'roles.crear',
            'roles.ver',
            'roles.editar',
            'roles.eliminar',
            'permisos.listar',

            // Módulo de Tipos de Documento
            'tipos_documento.listar',
            'catalogos.listar',

            'prestadores.listar',
            'prestadores.crear',
            'prestadores.ver',
            'prestadores.editar',
            'prestadores.eliminar',
            'prestadores.restaurar',

            'sedes.listar',
            'sedes.crear',
            'sedes.ver',
            'sedes.editar',
            'sedes.eliminar',
            'sedes.restaurar',

            'especialidades.listar',
            'especialidades.crear',
            'especialidades.ver',
            'especialidades.editar',
            'especialidades.eliminar',
            'especialidades.restaurar',

            'portafolio.listar',
            'portafolio.crear',
            'portafolio.editar',
            'portafolio.eliminar',
        ];

        foreach ($permisos as $nombrePermiso) {
            Permission::firstOrCreate([
                'name' => $nombrePermiso,
                'guard_name' => 'web',
            ]);
        }
    }
}
