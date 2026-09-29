<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Rol Super Administrador (Acceso total)
        $rolSuperAdmin = Role::firstOrCreate([
            'name' => 'super-admin',
            'guard_name' => 'web',
        ]);
        $todosLosPermisos = Permission::all();
        $rolSuperAdmin->syncPermissions($todosLosPermisos);

        // 2. Rol Administrador
        $rolAdmin = Role::firstOrCreate([
            'name' => 'administrador',
            'guard_name' => 'web',
        ]);
        $rolAdmin->syncPermissions([
            'usuarios.listar',
            'usuarios.crear',
            'usuarios.ver',
            'usuarios.editar',
            'operadores.listar',
            'operadores.crear',
            'operadores.ver',
            'operadores.editar',
            'roles.listar',
            'roles.ver',
            'permisos.listar',
            'tipos_documento.listar',
        ]);

        // 3. Rol Operador
        $rolOperador = Role::firstOrCreate([
            'name' => 'operador',
            'guard_name' => 'web',
        ]);
        $rolOperador->syncPermissions([
            'operadores.ver',
            'tipos_documento.listar',
        ]);

        // 4. Rol Solo Consulta / Auditor
        $rolConsulta = Role::firstOrCreate([
            'name' => 'consulta',
            'guard_name' => 'web',
        ]);
        $rolConsulta->syncPermissions([
            'usuarios.listar',
            'usuarios.ver',
            'operadores.listar',
            'operadores.ver',
            'roles.listar',
            'roles.ver',
            'permisos.listar',
            'tipos_documento.listar',
        ]);
    }
}
