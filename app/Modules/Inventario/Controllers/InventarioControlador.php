<?php

namespace App\Modules\Inventario\Controllers;

use App\Modules\Common\Controllers\ControladorBase;
use App\Modules\Common\Services\LectorCsv;
use App\Modules\Inventario\Models\Item;
use App\Modules\Inventario\Models\Mantenimiento;
use App\Modules\Inventario\Models\Unidad;
use App\Modules\Inventario\Services\AlertasInventario;
use App\Modules\Inventario\Services\ExistenciaServicio;
use App\Modules\Inventario\Services\InventarioServicio;
use App\Modules\Inventario\Services\RequerimientoServicio;
use App\Modules\Inventario\Services\SincronizadorInventario;
use App\Modules\Inventario\Services\VerificadorRecursos;
use App\Modules\Red\Models\Sala;
use App\Modules\Servicios\Models\PortafolioItem;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventarioControlador extends ControladorBase
{
    public function __construct(
        protected InventarioServicio $inventario,
        protected ExistenciaServicio $existencias,
        protected RequerimientoServicio $requerimientos,
        protected VerificadorRecursos $verificador,
        protected AlertasInventario $alertas,
        protected SincronizadorInventario $sincronizador,
        protected LectorCsv $lector
    ) {}

    public function resumen(): JsonResponse
    {
        return $this->ejecutar(fn () => $this->alertas->resumen(), 'Resumen del inventario.', 'Error al obtener el resumen del inventario.');
    }

    // ------------------------------------------------------------------ Ítems

    public function items(Request $request): JsonResponse
    {
        return $this->ejecutar(fn () => $this->inventario->listarItems($request->only(['tipo', 'buscar', 'activo']), (int) $request->input('por_pagina', 50)), 'Ítems del inventario.', 'Error al obtener los ítems.');
    }

    public function guardarItem(Request $request, int|string|null $id = null): JsonResponse
    {
        $requerido = $id ? 'sometimes' : 'required';
        $datos = $request->validate([
            'tipo' => [$requerido, Rule::in(Item::TIPOS)],
            'codigo' => [$requerido, 'string', 'max:40', Rule::unique('inventario_items', 'codigo')->ignore($id)->whereNull('deleted_at')],
            'nombre' => [$requerido, 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'unidad_medida' => ['nullable', 'string', 'max:30'],
            'clasificacion_riesgo' => ['nullable', Rule::in(['I', 'IIA', 'IIB', 'III'])],
            'requiere_calibracion' => ['nullable', 'boolean'],
            'periodicidad_mantenimiento_meses' => ['nullable', 'integer', 'min:1', 'max:60'],
            'periodicidad_calibracion_meses' => ['nullable', 'integer', 'min:1', 'max:60'],
            'minutos_esterilizacion' => ['nullable', 'integer', 'min:0', 'max:2880'],
            'stock_minimo' => ['nullable', 'integer', 'min:0'],
            'activo' => ['nullable', 'boolean'],
        ], [
            'codigo.unique' => 'Ya existe un ítem con este código.',
            'codigo.required' => 'El código es obligatorio.',
            'nombre.required' => 'El nombre es obligatorio.',
            'tipo.in' => 'El tipo debe ser EQUIPO, INSTRUMENTAL o INSUMO.',
        ]);

        return $this->ejecutar(
            fn () => $id ? $this->inventario->actualizarItem((int) $id, $datos) : $this->inventario->crearItem($datos),
            $id ? 'Ítem actualizado.' : 'Ítem creado.',
            'Error al guardar el ítem.',
            $id ? 200 : 201
        );
    }

    public function eliminarItem(int|string $id): JsonResponse
    {
        return $this->ejecutar(function () use ($id) {
            $this->inventario->eliminarItem((int) $id);

            return null;
        }, 'Ítem eliminado.', 'Error al eliminar el ítem.');
    }

    // ------------------------------------------------------------------ Unidades

    public function unidades(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->inventario->listarUnidades($request->only(['tipo', 'item_id', 'sede_id', 'sala_id', 'estado', 'alerta', 'buscar']), (int) $request->input('por_pagina', 25)),
            'Unidades del inventario.',
            'Error al obtener las unidades.'
        );
    }

    public function unidad(int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->inventario->obtenerUnidad((int) $id), 'Detalle de la unidad.', 'Error al consultar la unidad.');
    }

    public function guardarUnidad(Request $request, int|string|null $id = null): JsonResponse
    {
        $requerido = $id ? 'sometimes' : 'required';
        $datos = $request->validate([
            'item_id' => [$requerido, 'integer', Rule::exists('inventario_items', 'id')->whereNull('deleted_at')],
            'sede_id' => [$requerido, 'integer', Rule::exists('sedes', 'id')->whereNull('deleted_at')],
            'sala_id' => ['nullable', 'integer', Rule::exists('salas', 'id')->whereNull('deleted_at')],
            'codigo' => [$requerido, 'string', 'max:60', Rule::unique('inventario_unidades', 'codigo')->ignore($id)],
            'serie' => ['nullable', 'string', 'max:80'],
            'marca' => ['nullable', 'string', 'max:80'],
            'modelo' => ['nullable', 'string', 'max:80'],
            'registro_invima' => ['nullable', 'string', 'max:60'],
            'estado' => ['nullable', Rule::in(Unidad::ESTADOS)],
            'ultimo_mantenimiento' => ['nullable', 'date_format:Y-m-d'],
            'proximo_mantenimiento' => ['nullable', 'date_format:Y-m-d'],
            'calibracion_vence' => ['nullable', 'date_format:Y-m-d'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ], [
            'codigo.unique' => 'Ya existe una unidad con esta placa o código.',
            'codigo.required' => 'La placa o código es obligatorio.',
            'item_id.required' => 'Elige el tipo.',
            'sede_id.required' => 'Elige la sede.',
        ]);

        return $this->ejecutar(fn () => $this->inventario->guardarUnidad($id ? (int) $id : null, $datos), $id ? 'Unidad actualizada.' : 'Unidad registrada.', 'Error al guardar la unidad.', $id ? 200 : 201);
    }

    public function eliminarUnidad(int|string $id): JsonResponse
    {
        return $this->ejecutar(function () use ($id) {
            $this->inventario->eliminarUnidad((int) $id);

            return null;
        }, 'Unidad eliminada.', 'Error al eliminar la unidad.');
    }

    public function programarMantenimiento(Request $request, int|string $id): JsonResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(Mantenimiento::TIPOS)],
            'inicio' => ['required', 'date_format:Y-m-d H:i'],
            'fin' => ['required', 'date_format:Y-m-d H:i', 'after:inicio'],
            'responsable' => ['nullable', 'string', 'max:150'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ], ['fin.after' => 'El fin debe ser posterior al inicio.']);

        return $this->ejecutar(fn () => $this->inventario->programarMantenimiento((int) $id, $datos), 'Mantenimiento programado: el equipo queda bloqueado en esa franja.', 'Error al programar el mantenimiento.', 201);
    }

    public function cerrarMantenimiento(Request $request, int|string $id): JsonResponse
    {
        $datos = $request->validate(['estado' => ['required', Rule::in(['TERMINADO', 'CANCELADO'])], 'observaciones' => ['nullable', 'string', 'max:500']]);

        return $this->ejecutar(
            fn () => $this->inventario->cerrarMantenimiento((int) $id, $datos['estado'], $datos['observaciones'] ?? null),
            $datos['estado'] === 'TERMINADO' ? 'Mantenimiento terminado.' : 'Mantenimiento cancelado.',
            'Error al cerrar el mantenimiento.'
        );
    }

    // ------------------------------------------------------------------ Insumos

    public function existencias(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->existencias->listar($request->only(['sede_id', 'buscar', 'bajo_minimo', 'por_vencer', 'page']), (int) $request->input('por_pagina', 25)),
            'Existencias de insumos.',
            'Error al obtener las existencias.'
        );
    }

    public function registrarMovimiento(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'item_id' => ['required', 'integer', 'exists:inventario_items,id'],
            'sede_id' => ['required', 'integer', Rule::exists('sedes', 'id')->whereNull('deleted_at')],
            'tipo' => ['required', Rule::in(['ENTRADA', 'SALIDA', 'AJUSTE'])],
            'cantidad' => ['required', 'integer', 'min:0', 'max:10000000'],
            'lote' => ['nullable', 'string', 'max:60'],
            'vence' => ['nullable', 'date_format:Y-m-d'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ], ['cantidad.required' => 'Indica la cantidad.']);
        if ($datos['tipo'] !== 'AJUSTE' && (int) $datos['cantidad'] === 0) {
            return $this->respuestaError('Los datos enviados no son válidos.', 422, ['cantidad' => ['La cantidad debe ser mayor que cero.']]);
        }

        return $this->ejecutar(fn () => $this->existencias->registrar($datos, $request->user()?->id), 'Movimiento registrado.', 'Error al registrar el movimiento.', 201);
    }

    public function movimientos(Request $request, int|string $itemId): JsonResponse
    {
        return $this->ejecutar(fn () => $this->existencias->movimientos((int) $itemId, $request->integer('sede_id') ?: null), 'Movimientos del insumo.', 'Error al obtener los movimientos.');
    }

    // ------------------------------------------------------------------ Requerimientos por CUPS

    public function cupsConRequerimientos(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->requerimientos->listarCups($request->only(['buscar', 'sin_requerimientos']), (int) $request->input('por_pagina', 30)),
            'CUPS del portafolio.',
            'Error al obtener los CUPS.'
        );
    }

    public function requerimientos(int|string $cupsId): JsonResponse
    {
        return $this->ejecutar(fn () => $this->requerimientos->detalle((int) $cupsId), 'Requerimientos del CUPS.', 'Error al obtener los requerimientos.');
    }

    public function guardarRequerimientos(Request $request, int|string $cupsId): JsonResponse
    {
        $datos = $request->validate([
            'sede_id' => ['nullable', 'integer', Rule::exists('sedes', 'id')->whereNull('deleted_at')],
            'items' => ['present', 'array'],
            'items.*.item_id' => ['required', 'integer', Rule::exists('inventario_items', 'id')->whereNull('deleted_at')],
            'items.*.cantidad' => ['required', 'integer', 'min:0', 'max:1000'],
            'items.*.notas' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->ejecutar(
            fn () => $this->requerimientos->guardar((int) $cupsId, $datos['sede_id'] ?? null, $datos['items']),
            empty($datos['sede_id']) ? 'Requerimientos guardados.' : 'Ajuste de la sede guardado.',
            'Error al guardar los requerimientos.'
        );
    }

    /**
     * Verifica si hay sala, equipos, instrumental e insumos para un CUPS en una sede y horario.
     */
    public function verificar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'cups_id' => ['required', 'integer', 'exists:cups,id'],
            'sede_id' => ['required', 'integer', 'exists:sedes,id'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'],
            'duracion' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        return $this->ejecutar(function () use ($datos) {
            $duracion = $datos['duracion'] ?? PortafolioItem::where('cups_id', $datos['cups_id'])->where('sede_id', $datos['sede_id'])->value('duracion_minutos') ?? 60;
            $inicio = Carbon::parse("{$datos['fecha']} {$datos['hora']}");

            return $this->verificador->verificar((int) $datos['cups_id'], (int) $datos['sede_id'], $inicio, $inicio->copy()->addMinutes((int) $duracion))
                + ['inicio' => $inicio->format('Y-m-d H:i'), 'fin' => $inicio->copy()->addMinutes((int) $duracion)->format('Y-m-d H:i')];
        }, 'Verificación de recursos.', 'Error al verificar los recursos.');
    }

    /**
     * Cargue CSV de unidades (equipos y cajas) o de existencias de insumos.
     */
    public function importar(Request $request, string $tipo): JsonResponse
    {
        $request->validate(['archivo' => ['required', 'file', 'max:10240', 'mimes:csv,txt'], 'simular' => ['nullable', 'boolean']], [
            'archivo.required' => 'Selecciona el archivo CSV.',
            'archivo.mimes' => 'El archivo debe ser CSV (en Excel: Guardar como → CSV UTF-8).',
        ]);
        $simular = $request->boolean('simular');

        return $this->ejecutar(function () use ($request, $tipo, $simular) {
            $ruta = $request->file('archivo')->getRealPath();
            if ($tipo === 'unidades') {
                return $this->sincronizador->unidades($this->lector->leer($ruta, ['tipo', 'item_codigo', 'codigo', 'sede'], 10000), $simular, 'CSV');
            }

            return $this->sincronizador->existencias($this->lector->leer($ruta, ['item_codigo', 'sede', 'cantidad'], 20000), $simular, 'CSV', $request->user()?->id);
        }, $simular ? 'Archivo revisado.' : 'Cargue de inventario terminado.', 'Error al procesar el archivo de inventario.');
    }

    // ------------------------------------------------------------------ Salas

    public function salas(Request $request): JsonResponse
    {
        return $this->ejecutar(fn () => $this->inventario->listarSalas($request->only(['sede_id', 'tipo'])), 'Salas.', 'Error al obtener las salas.');
    }

    public function guardarSala(Request $request, int|string|null $id = null): JsonResponse
    {
        $requerido = $id ? 'sometimes' : 'required';
        $sedeId = $request->input('sede_id', $id ? Sala::find($id)?->sede_id : null);
        $datos = $request->validate([
            'sede_id' => [$requerido, 'integer', Rule::exists('sedes', 'id')->whereNull('deleted_at')],
            'codigo' => [$requerido, 'string', 'max:20', Rule::unique('salas', 'codigo')->where('sede_id', $sedeId)->whereNull('deleted_at')->ignore($id)],
            'nombre' => [$requerido, 'string', 'max:100'],
            'tipo' => [$requerido, Rule::in(Sala::TIPOS)],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'activo' => ['nullable', 'boolean'],
        ], ['codigo.unique' => 'La sede ya tiene una sala con este código.']);

        return $this->ejecutar(fn () => $this->inventario->guardarSala($id ? (int) $id : null, $datos), $id ? 'Sala actualizada.' : 'Sala creada.', 'Error al guardar la sala.', $id ? 200 : 201);
    }

    public function eliminarSala(int|string $id): JsonResponse
    {
        return $this->ejecutar(function () use ($id) {
            $this->inventario->eliminarSala((int) $id);

            return null;
        }, 'Sala eliminada.', 'Error al eliminar la sala.');
    }

    // ------------------------------------------------------------------ Integración

    public function integracionUnidades(Request $request): JsonResponse
    {
        $request->validate(['unidades' => ['required', 'array', 'min:1', 'max:5000'], 'simular' => ['nullable', 'boolean']]);

        return $this->ejecutar(
            fn () => $this->sincronizador->unidades(array_combine(range(1, count($request->input('unidades'))), $request->input('unidades')), $request->boolean('simular')),
            'Unidades sincronizadas.',
            'Error al sincronizar las unidades.'
        );
    }

    public function integracionExistencias(Request $request): JsonResponse
    {
        $request->validate(['existencias' => ['required', 'array', 'min:1', 'max:20000'], 'simular' => ['nullable', 'boolean']]);

        return $this->ejecutar(
            fn () => $this->sincronizador->existencias(array_combine(range(1, count($request->input('existencias'))), $request->input('existencias')), $request->boolean('simular')),
            'Existencias sincronizadas.',
            'Error al sincronizar las existencias.'
        );
    }
}
