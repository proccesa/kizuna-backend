<?php

use App\Modules\Auth\Controllers\AutenticacionControlador;
use App\Modules\Roles\Controllers\RolPermisoControlador;
use App\Modules\Users\Controllers\OperadorControlador;
use App\Modules\Users\Controllers\TipoDocumentoControlador;
use App\Modules\Users\Controllers\UsuarioControlador;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Kizuna API v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // Rutas públicas de Autenticación
    Route::prefix('auth')->group(function () {
        Route::post('login', [AutenticacionControlador::class, 'login']);
    });

    // Rutas protegidas por Sanctum
    Route::middleware('auth:sanctum')->group(function () {

        // Autenticación y sesión activa
        Route::prefix('auth')->group(function () {
            Route::get('perfil', [AutenticacionControlador::class, 'perfil']);
            Route::post('logout', [AutenticacionControlador::class, 'logout']);
        });

        // Catálogos (Tipos de documento)
        Route::prefix('tipos-documento')->group(function () {
            Route::get('/', [TipoDocumentoControlador::class, 'index']);
            Route::get('{id}', [TipoDocumentoControlador::class, 'show']);
        });

        // Roles y Permisos
        Route::get('roles', [RolPermisoControlador::class, 'roles']);
        Route::get('permisos', [RolPermisoControlador::class, 'permisos']);

        // Módulo de Usuarios (CRUD completo + soft delete y restauración)
        Route::prefix('usuarios')->group(function () {
            Route::get('/', [UsuarioControlador::class, 'index']);
            Route::post('/', [UsuarioControlador::class, 'store']);
            Route::get('{id}', [UsuarioControlador::class, 'show']);
            Route::put('{id}', [UsuarioControlador::class, 'update']);
            Route::delete('{id}', [UsuarioControlador::class, 'destroy']);
            Route::patch('{id}/restaurar', [UsuarioControlador::class, 'restore']);
            Route::patch('{id}/estado', [UsuarioControlador::class, 'toggleStatus']);
        });

        // Módulo de Operadores (CRUD completo + soft delete y restauración)
        Route::prefix('operadores')->group(function () {
            Route::get('/', [OperadorControlador::class, 'index']);
            Route::post('/', [OperadorControlador::class, 'store']);
            Route::get('{id}', [OperadorControlador::class, 'show']);
            Route::put('{id}', [OperadorControlador::class, 'update']);
            Route::delete('{id}', [OperadorControlador::class, 'destroy']);
            Route::patch('{id}/restaurar', [OperadorControlador::class, 'restore']);
        });

    });

});
