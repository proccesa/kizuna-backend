<?php

namespace App\Modules\Cirugia\Controllers;

use App\Modules\Cirugia\Requests\GuardarOrdenRequest;
use App\Modules\Cirugia\Services\ImportadorOrdenes;
use App\Modules\Cirugia\Services\OrdenServicio;
use App\Modules\Cirugia\Services\ReglasPreanestesia;
use App\Modules\Common\Controllers\ControladorBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrdenControlador extends ControladorBase
{
    public function __construct(
        protected OrdenServicio $ordenServicio,
        protected ImportadorOrdenes $importador,
        protected ReglasPreanestesia $reglas
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->ordenServicio->listar($request->only(['estado', 'buscar', 'prioridad', 'especialidad_id', 'contrato_id', 'paciente_id']), (int) $request->input('por_pagina', 20)),
            'Listado de órdenes obtenido exitosamente.',
            'Error al obtener las órdenes.'
        );
    }

    public function resumen(): JsonResponse
    {
        return $this->ejecutar(fn () => $this->ordenServicio->resumen(), 'Resumen de órdenes obtenido exitosamente.', 'Error al obtener el resumen.');
    }

    public function show(int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->ordenServicio->obtenerPorId((int) $id), 'Detalle de la orden obtenido exitosamente.', 'Error al consultar la orden.');
    }

    public function store(GuardarOrdenRequest $request): JsonResponse
    {
        return $this->ejecutar(fn () => $this->ordenServicio->registrar($request->validated(), 'MANUAL')['orden'], 'Orden registrada.', 'Error al registrar la orden.', 201);
    }

    public function importar(Request $request): JsonResponse
    {
        $request->validate(['archivo' => ['required', 'file', 'max:10240', 'mimes:csv,txt'], 'simular' => ['nullable', 'boolean']], [
            'archivo.required' => 'Selecciona el archivo CSV.',
            'archivo.mimes' => 'El archivo debe ser CSV (en Excel: Guardar como → CSV UTF-8).',
        ]);
        $simular = $request->boolean('simular');

        return $this->ejecutar(
            fn () => $this->importador->importar($request->file('archivo')->getRealPath(), $simular),
            $simular ? 'Archivo revisado.' : 'Cargue de órdenes terminado.',
            'Error al procesar el archivo de órdenes.'
        );
    }

    public function cupos(Request $request, int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->ordenServicio->cupos((int) $id, $request->input('desde')), 'Cupos disponibles.', 'Error al consultar los cupos.');
    }

    public function reprogramar(Request $request, int|string $id): JsonResponse
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'especialista_id' => ['required', 'integer'],
            'sede_id' => ['required', 'integer'],
        ]);

        return $this->ejecutar(fn () => $this->ordenServicio->reprogramar((int) $id, $datos), 'Cita de pre-anestesia reprogramada.', 'Error al reprogramar la cita.');
    }

    public function revalidar(int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->ordenServicio->revalidar((int) $id), 'Orden revalidada.', 'Error al revalidar la orden.');
    }

    public function cancelar(Request $request, int|string $id): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:255']], ['motivo.required' => 'Indica el motivo de la cancelación.']);

        return $this->ejecutar(fn () => $this->ordenServicio->cancelar((int) $id, $datos['motivo']), 'Orden cancelada.', 'Error al cancelar la orden.');
    }

    /**
     * Reintenta asignar cita a todas las órdenes pendientes.
     */
    public function asignarPendientes(): JsonResponse
    {
        return $this->ejecutar(fn () => $this->ordenServicio->asignarPendientes(), 'Asignación de citas terminada.', 'Error al asignar las citas.');
    }

    public function paciente(Request $request): JsonResponse
    {
        $datos = $request->validate(['tipo_documento' => ['required', 'string'], 'numero_documento' => ['required', 'string']]);

        return $this->ejecutar(fn () => $this->ordenServicio->pacientePorDocumento($datos['tipo_documento'], $datos['numero_documento']), 'Búsqueda de paciente.', 'Error al buscar el paciente.');
    }

    public function reglas(): JsonResponse
    {
        return $this->ejecutar(fn () => $this->reglas->conCatalogos(), 'Reglas de pre-anestesia.', 'Error al obtener las reglas.');
    }

    public function guardarReglas(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'cups_consulta_codigo' => ['sometimes', 'string', Rule::exists('cups', 'codigo')->where('habilitado', true)],
            'especialidad_codigo' => ['sometimes', 'string', Rule::exists('especialidades', 'codigo')->whereNull('deleted_at')],
            'vigencia_dias_por_asa' => ['sometimes', 'array'],
            'vigencia_dias_por_asa.*' => ['integer', 'min:1', 'max:730'],
            'horizonte_dias' => ['sometimes', 'integer', 'min:7', 'max:365'],
            'dias_anticipacion' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'duracion_minutos' => ['sometimes', 'integer', 'min:5', 'max:240'],
        ], [
            'cups_consulta_codigo.exists' => 'El CUPS no existe o no está habilitado.',
            'especialidad_codigo.exists' => 'La especialidad no existe.',
            'vigencia_dias_por_asa.*.min' => 'La vigencia mínima es 1 día.',
        ]);

        return $this->ejecutar(fn () => $this->reglas->guardar($datos), 'Reglas de pre-anestesia actualizadas.', 'Error al guardar las reglas.');
    }
}
