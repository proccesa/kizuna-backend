<?php

use App\Modules\Auth\Controllers\AutenticacionControlador;
use App\Modules\Catalogos\Controllers\CatalogoControlador;
use App\Modules\Cirugia\Controllers\OrdenControlador;
use App\Modules\Citas\Controllers\CitaControlador;
use App\Modules\Contratacion\Controllers\ContratoControlador;
use App\Modules\Contratacion\Controllers\EntidadControlador;
use App\Modules\Contratacion\Controllers\PoblacionControlador;
use App\Modules\HistoriaClinica\Controllers\HistoriaControlador;
use App\Modules\Integracion\Controllers\ClienteIntegracionControlador;
use App\Modules\Integracion\Controllers\IntegracionControlador;
use App\Modules\Inventario\Controllers\InventarioControlador;
use App\Modules\Programacion\Controllers\ProgramacionControlador;
use App\Modules\Red\Controllers\PrestadorControlador;
use App\Modules\Red\Controllers\SedeControlador;
use App\Modules\Roles\Controllers\RolPermisoControlador;
use App\Modules\Servicios\Controllers\EspecialidadControlador;
use App\Modules\Servicios\Controllers\PortafolioControlador;
use App\Modules\Talento\Controllers\AgendaControlador;
use App\Modules\Talento\Controllers\EspecialistaControlador;
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

    // Integración con sistemas externos: token de cliente de integración con permisos (abilities)
    Route::prefix('integracion')->middleware(['auth:sanctum', 'solo-integracion'])->group(function () {
        Route::post('ordenes', [IntegracionControlador::class, 'crearOrden'])->middleware('abilities:ordenes:escribir');
        Route::get('ordenes/{referencia}', [IntegracionControlador::class, 'estadoOrden'])->middleware('abilities:ordenes:leer');
        Route::post('historias/preanestesia', [IntegracionControlador::class, 'recibirHistoria'])->middleware('abilities:historias:escribir');
        Route::post('inventario/unidades', [InventarioControlador::class, 'integracionUnidades'])->middleware('abilities:inventario:escribir');
        Route::post('inventario/existencias', [InventarioControlador::class, 'integracionExistencias'])->middleware('abilities:inventario:escribir');
    });

    // Rutas protegidas por Sanctum (usuarios de la aplicación)
    Route::middleware(['auth:sanctum', 'solo-usuarios'])->group(function () {

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

        // Programación quirúrgica: el motor propone, el jefe de cirugía aprueba
        Route::prefix('programacion')->group(function () {
            Route::get('resumen', [ProgramacionControlador::class, 'resumen'])->middleware('permission:programacion.listar');
            Route::get('cola', [ProgramacionControlador::class, 'cola'])->middleware('permission:programacion.listar');
            Route::get('propuesta', [ProgramacionControlador::class, 'propuesta'])->middleware('permission:programacion.listar');
            Route::get('programa', [ProgramacionControlador::class, 'programa'])->middleware('permission:programacion.listar');
            Route::get('reglas', [ProgramacionControlador::class, 'reglas'])->middleware('permission:programacion.listar');
            Route::put('reglas', [ProgramacionControlador::class, 'guardarReglas'])->middleware('permission:programacion.aprobar');
            Route::post('generar', [ProgramacionControlador::class, 'generar'])->middleware('permission:programacion.generar');
            Route::post('corridas/{id}/aprobar', [ProgramacionControlador::class, 'aprobar'])->middleware('permission:programacion.aprobar');
            Route::post('corridas/{id}/descartar', [ProgramacionControlador::class, 'descartar'])->middleware('permission:programacion.generar');
            Route::post('cirugias/{id}/rechazar', [ProgramacionControlador::class, 'rechazar'])->middleware('permission:programacion.generar');
            Route::post('cirugias/{id}/cancelar', [ProgramacionControlador::class, 'cancelar'])->middleware('permission:programacion.aprobar');
            Route::post('cirugias/{id}/realizar', [ProgramacionControlador::class, 'realizar'])->middleware('permission:programacion.realizar');
        });

        // Inventario: biomédicos, central (instrumental e insumos), salas y requerimientos por CUPS
        Route::prefix('inventario')->group(function () {
            Route::get('resumen', [InventarioControlador::class, 'resumen'])->middleware('permission:inventario.listar');
            Route::get('verificar', [InventarioControlador::class, 'verificar'])->middleware('permission:inventario.listar');

            Route::get('items', [InventarioControlador::class, 'items'])->middleware('permission:inventario.listar');
            Route::post('items', [InventarioControlador::class, 'guardarItem'])->middleware('permission:inventario.gestionar');
            Route::put('items/{id}', [InventarioControlador::class, 'guardarItem'])->middleware('permission:inventario.gestionar');
            Route::delete('items/{id}', [InventarioControlador::class, 'eliminarItem'])->middleware('permission:inventario.gestionar');

            Route::get('unidades', [InventarioControlador::class, 'unidades'])->middleware('permission:inventario.listar');
            Route::post('unidades', [InventarioControlador::class, 'guardarUnidad'])->middleware('permission:inventario.gestionar');
            Route::get('unidades/{id}', [InventarioControlador::class, 'unidad'])->middleware('permission:inventario.listar');
            Route::put('unidades/{id}', [InventarioControlador::class, 'guardarUnidad'])->middleware('permission:inventario.gestionar');
            Route::delete('unidades/{id}', [InventarioControlador::class, 'eliminarUnidad'])->middleware('permission:inventario.gestionar');
            Route::post('unidades/{id}/mantenimientos', [InventarioControlador::class, 'programarMantenimiento'])->middleware('permission:inventario.gestionar');
            Route::patch('mantenimientos/{id}', [InventarioControlador::class, 'cerrarMantenimiento'])->middleware('permission:inventario.gestionar');

            Route::get('existencias', [InventarioControlador::class, 'existencias'])->middleware('permission:inventario.listar');
            Route::post('movimientos', [InventarioControlador::class, 'registrarMovimiento'])->middleware('permission:inventario.gestionar');
            Route::get('items/{id}/movimientos', [InventarioControlador::class, 'movimientos'])->middleware('permission:inventario.listar');

            Route::post('importar/{tipo}', [InventarioControlador::class, 'importar'])->whereIn('tipo', ['unidades', 'existencias'])->middleware('permission:inventario.gestionar');

            Route::get('requerimientos', [InventarioControlador::class, 'cupsConRequerimientos'])->middleware('permission:inventario.listar');
            Route::get('requerimientos/{cupsId}', [InventarioControlador::class, 'requerimientos'])->middleware('permission:inventario.listar');
            Route::put('requerimientos/{cupsId}', [InventarioControlador::class, 'guardarRequerimientos'])->middleware('permission:inventario.gestionar');
        });

        Route::get('salas', [InventarioControlador::class, 'salas'])->middleware('permission:sedes.listar');
        Route::post('salas', [InventarioControlador::class, 'guardarSala'])->middleware('permission:sedes.editar');
        Route::put('salas/{id}', [InventarioControlador::class, 'guardarSala'])->middleware('permission:sedes.editar');
        Route::delete('salas/{id}', [InventarioControlador::class, 'eliminarSala'])->middleware('permission:sedes.eliminar');

        // Pre-anestesia: órdenes quirúrgicas, citas e historias clínicas
        Route::prefix('ordenes')->group(function () {
            Route::get('/', [OrdenControlador::class, 'index'])->middleware('permission:ordenes.listar');
            Route::post('/', [OrdenControlador::class, 'store'])->middleware('permission:ordenes.crear');
            Route::get('resumen', [OrdenControlador::class, 'resumen'])->middleware('permission:ordenes.listar');
            Route::post('importar', [OrdenControlador::class, 'importar'])->middleware('permission:ordenes.crear');
            Route::post('asignar-pendientes', [OrdenControlador::class, 'asignarPendientes'])->middleware('permission:ordenes.gestionar');
            Route::get('paciente', [OrdenControlador::class, 'paciente'])->middleware('permission:ordenes.crear');
            Route::get('reglas', [OrdenControlador::class, 'reglas'])->middleware('permission:ordenes.listar');
            Route::put('reglas', [OrdenControlador::class, 'guardarReglas'])->middleware('permission:preanestesia.configurar');
            Route::get('{id}', [OrdenControlador::class, 'show'])->middleware('permission:ordenes.ver');
            Route::get('{id}/cupos', [OrdenControlador::class, 'cupos'])->middleware('permission:ordenes.gestionar');
            Route::post('{id}/reprogramar', [OrdenControlador::class, 'reprogramar'])->middleware('permission:ordenes.gestionar');
            Route::post('{id}/revalidar', [OrdenControlador::class, 'revalidar'])->middleware('permission:ordenes.gestionar');
            Route::post('{id}/cancelar', [OrdenControlador::class, 'cancelar'])->middleware('permission:ordenes.gestionar');
        });

        Route::get('citas', [CitaControlador::class, 'index'])->middleware('permission:citas.listar');
        Route::patch('citas/{id}/estado', [CitaControlador::class, 'cambiarEstado'])->middleware('permission:ordenes.gestionar');

        Route::get('plantillas-hc', [HistoriaControlador::class, 'plantillas'])->middleware('permission:historias.listar');
        Route::prefix('historias')->group(function () {
            Route::get('/', [HistoriaControlador::class, 'index'])->middleware('permission:historias.listar');
            Route::post('/', [HistoriaControlador::class, 'store'])->middleware('permission:historias.diligenciar');
            Route::get('{id}', [HistoriaControlador::class, 'show'])->middleware('permission:historias.ver');
            Route::put('{id}', [HistoriaControlador::class, 'update'])->middleware('permission:historias.diligenciar');
            Route::post('{id}/finalizar', [HistoriaControlador::class, 'finalizar'])->middleware('permission:historias.diligenciar');
            Route::post('{id}/anular', [HistoriaControlador::class, 'anular'])->middleware('permission:historias.anular');
        });

        Route::prefix('clientes-integracion')->middleware('permission:integraciones.gestionar')->group(function () {
            Route::get('/', [ClienteIntegracionControlador::class, 'index']);
            Route::post('/', [ClienteIntegracionControlador::class, 'store']);
            Route::put('{id}', [ClienteIntegracionControlador::class, 'update']);
            Route::post('{id}/regenerar', [ClienteIntegracionControlador::class, 'regenerar']);
            Route::delete('{id}', [ClienteIntegracionControlador::class, 'destroy']);
        });

        // Contratación: entidades, contratos (sedes y CUPS pactados) y poblaciones de pacientes
        Route::prefix('entidades')->group(function () {
            Route::get('/', [EntidadControlador::class, 'index'])->middleware('permission:entidades.listar');
            Route::post('/', [EntidadControlador::class, 'store'])->middleware('permission:entidades.crear');
            Route::get('{id}', [EntidadControlador::class, 'show'])->middleware('permission:entidades.ver');
            Route::put('{id}', [EntidadControlador::class, 'update'])->middleware('permission:entidades.editar');
            Route::delete('{id}', [EntidadControlador::class, 'destroy'])->middleware('permission:entidades.eliminar');
            Route::patch('{id}/restaurar', [EntidadControlador::class, 'restore'])->middleware('permission:entidades.restaurar');
        });

        Route::prefix('contratos')->group(function () {
            Route::get('/', [ContratoControlador::class, 'index'])->middleware('permission:contratos.listar');
            Route::post('/', [ContratoControlador::class, 'store'])->middleware('permission:contratos.crear');
            Route::get('resumen', [ContratoControlador::class, 'resumen'])->middleware('permission:contratos.listar');
            Route::get('{id}', [ContratoControlador::class, 'show'])->middleware('permission:contratos.ver');
            Route::put('{id}', [ContratoControlador::class, 'update'])->middleware('permission:contratos.editar');
            Route::delete('{id}', [ContratoControlador::class, 'destroy'])->middleware('permission:contratos.eliminar');
            Route::patch('{id}/restaurar', [ContratoControlador::class, 'restore'])->middleware('permission:contratos.restaurar');

            Route::get('{id}/cups', [ContratoControlador::class, 'cups'])->middleware('permission:contratos.ver');
            Route::post('{id}/cups', [ContratoControlador::class, 'agregarCups'])->middleware('permission:contratos.editar');
            Route::put('{id}/cups/{cupsId}', [ContratoControlador::class, 'actualizarCups'])->middleware('permission:contratos.editar');
            Route::delete('{id}/cups/{cupsId}', [ContratoControlador::class, 'quitarCups'])->middleware('permission:contratos.editar');
        });

        Route::prefix('poblaciones')->group(function () {
            Route::get('/', [PoblacionControlador::class, 'index'])->middleware('permission:poblaciones.listar');
            Route::post('/', [PoblacionControlador::class, 'store'])->middleware('permission:poblaciones.crear');
            Route::get('resumen', [PoblacionControlador::class, 'resumen'])->middleware('permission:poblaciones.listar');
            Route::get('{id}', [PoblacionControlador::class, 'show'])->middleware('permission:poblaciones.ver');
            Route::put('{id}', [PoblacionControlador::class, 'update'])->middleware('permission:poblaciones.editar');
            Route::delete('{id}', [PoblacionControlador::class, 'destroy'])->middleware('permission:poblaciones.eliminar');
            Route::patch('{id}/restaurar', [PoblacionControlador::class, 'restore'])->middleware('permission:poblaciones.restaurar');

            Route::get('{id}/pacientes', [PoblacionControlador::class, 'pacientes'])->middleware('permission:poblaciones.ver');
            Route::post('{id}/cargar', [PoblacionControlador::class, 'cargar'])->middleware('permission:poblaciones.cargar');
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

        // Talento humano: especialistas, sus agendas (franjas semanales) y novedades
        Route::prefix('especialistas')->group(function () {
            Route::get('/', [EspecialistaControlador::class, 'index'])->middleware('permission:especialistas.listar');
            Route::post('/', [EspecialistaControlador::class, 'store'])->middleware('permission:especialistas.crear');
            Route::get('resumen', [EspecialistaControlador::class, 'resumen'])->middleware('permission:especialistas.listar');
            Route::post('importar', [EspecialistaControlador::class, 'importar'])->middleware('permission:especialistas.importar');
            Route::get('{id}', [EspecialistaControlador::class, 'show'])->middleware('permission:especialistas.ver');
            Route::put('{id}', [EspecialistaControlador::class, 'update'])->middleware('permission:especialistas.editar');
            Route::delete('{id}', [EspecialistaControlador::class, 'destroy'])->middleware('permission:especialistas.eliminar');
            Route::patch('{id}/restaurar', [EspecialistaControlador::class, 'restore'])->middleware('permission:especialistas.restaurar');

            Route::post('{id}/agendas', [AgendaControlador::class, 'store'])->middleware('permission:agendas.gestionar');
            Route::post('{id}/ausencias', [AgendaControlador::class, 'storeAusencia'])->middleware('permission:agendas.gestionar');
        });

        Route::prefix('agendas')->group(function () {
            Route::get('/', [AgendaControlador::class, 'index'])->middleware('permission:agendas.listar');
            Route::put('{agendaId}', [AgendaControlador::class, 'update'])->middleware('permission:agendas.gestionar');
            Route::delete('{agendaId}', [AgendaControlador::class, 'destroy'])->middleware('permission:agendas.gestionar');
        });

        Route::delete('ausencias/{ausenciaId}', [AgendaControlador::class, 'destroyAusencia'])->middleware('permission:agendas.gestionar');

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
