<?php

use App\Modules\Auth\Controllers\AutenticacionControlador;
use App\Modules\Catalogos\Controllers\CatalogoControlador;
use App\Modules\Red\Controllers\PrestadorControlador;
use App\Modules\Red\Controllers\SedeControlador;
use App\Modules\Roles\Controllers\RolPermisoControlador;
use App\Modules\Servicios\Controllers\EspecialidadControlador;
use App\Modules\Servicios\Controllers\PortafolioControlador;
use App\Modules\Users\Controllers\OperadorControlador;
use App\Modules\Users\Controllers\TipoDocumentoControlador;
use App\Modules\Users\Controllers\UsuarioControlador;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Kizuna API v1
|--------------------------------------------------------------------------
|
| Cada ruta protegida exige un permiso de Spatie (middleware `permission`).
| Los nombres de los permisos están definidos en Database\Seeders\PermisoSeeder.
| El rol `super-admin` tiene acceso total (Gate::before en AppServiceProvider).
|
*/

Route::prefix('v1')->group(function () {

    // Rutas públicas de Autenticación
    Route::prefix('auth')->group(function () {
        Route::post('login', [AutenticacionControlador::class, 'login']);
    });

    // Rutas protegidas por Sanctum
    Route::middleware('auth:sanctum')->group(function () {

        // Autenticación y sesión activa (solo requieren sesión)
        Route::prefix('auth')->group(function () {
            Route::get('perfil', [AutenticacionControlador::class, 'perfil']);
            Route::post('logout', [AutenticacionControlador::class, 'logout']);
        });

        // Catálogos base (DIVIPOLA, regímenes, modalidades de contratación)
        Route::prefix('catalogos')->middleware('permission:catalogos.listar')->group(function () {
            Route::get('departamentos', [CatalogoControlador::class, 'departamentos']);
            Route::get('departamentos/{id}/municipios', [CatalogoControlador::class, 'municipiosPorDepartamento']);
            Route::get('municipios', [CatalogoControlador::class, 'municipios']);
            Route::get('municipios/{id}', [CatalogoControlador::class, 'municipio']);
            Route::get('regimenes', [CatalogoControlador::class, 'regimenes']);
            Route::get('modalidades-contratacion', [CatalogoControlador::class, 'modalidadesContratacion']);
            Route::get('cups', [CatalogoControlador::class, 'cups']);
            Route::get('cups/{id}', [CatalogoControlador::class, 'cupsDetalle']);
        });

        // Servicios: especialidades, relación CUPS ↔ especialidad y portafolio por sede
        Route::prefix('especialidades')->group(function () {
            Route::get('/', [EspecialidadControlador::class, 'index'])->middleware('permission:especialidades.listar');
            Route::post('/', [EspecialidadControlador::class, 'store'])->middleware('permission:especialidades.crear');
            Route::get('{id}', [EspecialidadControlador::class, 'show'])->middleware('permission:especialidades.ver');
            Route::put('{id}', [EspecialidadControlador::class, 'update'])->middleware('permission:especialidades.editar');
            Route::delete('{id}', [EspecialidadControlador::class, 'destroy'])->middleware('permission:especialidades.eliminar');
            Route::patch('{id}/restaurar', [EspecialidadControlador::class, 'restore'])->middleware('permission:especialidades.restaurar');

            Route::get('{id}/cups', [EspecialidadControlador::class, 'cups'])->middleware('permission:especialidades.ver');
            Route::post('{id}/cups/{cupsId}', [EspecialidadControlador::class, 'asignarCups'])->middleware('permission:especialidades.editar');
            Route::delete('{id}/cups/{cupsId}', [EspecialidadControlador::class, 'quitarCups'])->middleware('permission:especialidades.editar');
        });

        Route::prefix('portafolio')->group(function () {
            Route::get('/', [PortafolioControlador::class, 'index'])->middleware('permission:portafolio.listar');
            Route::post('/', [PortafolioControlador::class, 'store'])->middleware('permission:portafolio.crear');
            Route::put('{id}', [PortafolioControlador::class, 'update'])->middleware('permission:portafolio.editar');
            Route::delete('{id}', [PortafolioControlador::class, 'destroy'])->middleware('permission:portafolio.eliminar');
        });

        // Red de atención: prestadores y sus sedes
        Route::prefix('prestadores')->group(function () {
            Route::get('/', [PrestadorControlador::class, 'index'])->middleware('permission:prestadores.listar');
            Route::post('/', [PrestadorControlador::class, 'store'])->middleware('permission:prestadores.crear');
            Route::get('{id}', [PrestadorControlador::class, 'show'])->middleware('permission:prestadores.ver');
            Route::put('{id}', [PrestadorControlador::class, 'update'])->middleware('permission:prestadores.editar');
            Route::delete('{id}', [PrestadorControlador::class, 'destroy'])->middleware('permission:prestadores.eliminar');
            Route::patch('{id}/restaurar', [PrestadorControlador::class, 'restore'])->middleware('permission:prestadores.restaurar');

            Route::get('{id}/sedes', [SedeControlador::class, 'indexDePrestador'])->middleware('permission:sedes.listar');
            Route::post('{id}/sedes', [SedeControlador::class, 'store'])->middleware('permission:sedes.crear');
        });

        Route::prefix('sedes')->group(function () {
            Route::get('/', [SedeControlador::class, 'index'])->middleware('permission:sedes.listar');
            Route::get('{sedeId}', [SedeControlador::class, 'show'])->middleware('permission:sedes.ver');
            Route::put('{sedeId}', [SedeControlador::class, 'update'])->middleware('permission:sedes.editar');
            Route::delete('{sedeId}', [SedeControlador::class, 'destroy'])->middleware('permission:sedes.eliminar');
            Route::patch('{sedeId}/restaurar', [SedeControlador::class, 'restore'])->middleware('permission:sedes.restaurar');
        });

        // Tipos de documento
        Route::prefix('tipos-documento')->middleware('permission:tipos_documento.listar')->group(function () {
            Route::get('/', [TipoDocumentoControlador::class, 'index']);
            Route::get('{id}', [TipoDocumentoControlador::class, 'show']);
        });

        // Roles y Permisos
        Route::get('roles', [RolPermisoControlador::class, 'roles'])->middleware('permission:roles.listar');
        Route::get('permisos', [RolPermisoControlador::class, 'permisos'])->middleware('permission:permisos.listar');

        // Módulo de Usuarios (CRUD completo + soft delete y restauración)
        Route::prefix('usuarios')->group(function () {
            Route::get('/', [UsuarioControlador::class, 'index'])->middleware('permission:usuarios.listar');
            Route::post('/', [UsuarioControlador::class, 'store'])->middleware('permission:usuarios.crear');
            Route::get('{id}', [UsuarioControlador::class, 'show'])->middleware('permission:usuarios.ver');
            Route::put('{id}', [UsuarioControlador::class, 'update'])->middleware('permission:usuarios.editar');
            Route::delete('{id}', [UsuarioControlador::class, 'destroy'])->middleware('permission:usuarios.eliminar');
            Route::patch('{id}/restaurar', [UsuarioControlador::class, 'restore'])->middleware('permission:usuarios.restaurar');
            Route::patch('{id}/estado', [UsuarioControlador::class, 'toggleStatus'])->middleware('permission:usuarios.editar');
        });

        // Módulo de Operadores (CRUD completo + soft delete y restauración)
        Route::prefix('operadores')->group(function () {
            Route::get('/', [OperadorControlador::class, 'index'])->middleware('permission:operadores.listar');
            Route::post('/', [OperadorControlador::class, 'store'])->middleware('permission:operadores.crear');
            Route::get('{id}', [OperadorControlador::class, 'show'])->middleware('permission:operadores.ver');
            Route::put('{id}', [OperadorControlador::class, 'update'])->middleware('permission:operadores.editar');
            Route::delete('{id}', [OperadorControlador::class, 'destroy'])->middleware('permission:operadores.eliminar');
            Route::patch('{id}/restaurar', [OperadorControlador::class, 'restore'])->middleware('permission:operadores.restaurar');
        });

    });

});
