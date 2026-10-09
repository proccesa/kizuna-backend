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
            'catalogos.listar',
            'prestadores.listar',
            'prestadores.crear',
            'prestadores.ver',
            'prestadores.editar',
            'sedes.listar',
            'sedes.crear',
            'sedes.ver',
            'sedes.editar',
            'especialidades.listar',
            'especialidades.crear',
            'especialidades.ver',
            'especialidades.editar',
            'portafolio.listar',
            'portafolio.crear',
            'portafolio.editar',
            'especialistas.listar',
            'especialistas.crear',
            'especialistas.ver',
            'especialistas.editar',
            'especialistas.importar',
            'agendas.listar',
            'agendas.gestionar',
            'entidades.listar',
            'entidades.crear',
            'entidades.ver',
            'entidades.editar',
            'contratos.listar',
            'contratos.crear',
            'contratos.ver',
            'contratos.editar',
            'poblaciones.listar',
            'poblaciones.crear',
            'poblaciones.ver',
            'poblaciones.editar',
            'poblaciones.cargar',
            'ordenes.listar',
            'ordenes.ver',
            'ordenes.crear',
            'ordenes.gestionar',
            'preanestesia.configurar',
            'citas.listar',
            'historias.listar',
            'historias.ver',
            'historias.diligenciar',
            'integraciones.gestionar',
            'inventario.listar',
            'inventario.gestionar',
            'programacion.listar',
            'programacion.generar',
            'programacion.aprobar',
            'programacion.realizar',
        ]);

        // 3. Rol Operador
        $rolOperador = Role::firstOrCreate([
            'name' => 'operador',
            'guard_name' => 'web',
        ]);
        $rolOperador->syncPermissions([
            'operadores.ver',
            'tipos_documento.listar',
            'catalogos.listar',
            'sedes.listar',
            'sedes.ver',
            'especialidades.listar',
            'portafolio.listar',
            'especialistas.listar',
            'especialistas.ver',
            'agendas.listar',
            'entidades.listar',
            'entidades.ver',
            'contratos.listar',
            'contratos.ver',
            'poblaciones.listar',
            'poblaciones.ver',
            'ordenes.listar',
            'ordenes.ver',
            'ordenes.crear',
            'ordenes.gestionar',
            'citas.listar',
            'inventario.listar',
            'programacion.listar',
            'programacion.generar',
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
            'catalogos.listar',
            'prestadores.listar',
            'prestadores.ver',
            'sedes.listar',
            'sedes.ver',
            'especialidades.listar',
            'especialidades.ver',
            'portafolio.listar',
            'especialistas.listar',
            'especialistas.ver',
            'agendas.listar',
            'entidades.listar',
            'entidades.ver',
            'contratos.listar',
            'contratos.ver',
            'poblaciones.listar',
            'poblaciones.ver',
            'ordenes.listar',
            'ordenes.ver',
            'citas.listar',
            'inventario.listar',
            'programacion.listar',
            'programacion.generar',
        ]);

        // 5. Rol Anestesiólogo / profesional asistencial: diligencia historias clínicas
        $rolProfesional = Role::firstOrCreate([
            'name' => 'profesional',
            'guard_name' => 'web',
        ]);
        $rolProfesional->syncPermissions([
            'ordenes.listar',
            'ordenes.ver',
            'citas.listar',
            'historias.listar',
            'historias.ver',
            'historias.diligenciar',
            'especialistas.listar',
            'especialistas.ver',
            'agendas.listar',
            'programacion.listar',
            'programacion.realizar',
        ]);
    }
}
