<?php

namespace App\Modules\Citas\Controllers;

use App\Modules\Citas\Services\CitaServicio;
use App\Modules\Common\Controllers\ControladorBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CitaControlador extends ControladorBase
{
    public function __construct(
        protected CitaServicio $citaServicio
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->citaServicio->listar($request->only(['fecha', 'desde', 'hasta', 'especialista_id', 'sede_id', 'paciente_id', 'orden_id', 'estado', 'tipo']), (int) $request->input('por_pagina', 50)),
            'Citas obtenidas exitosamente.',
            'Error al obtener las citas.'
        );
    }

    public function cambiarEstado(Request $request, int|string $id): JsonResponse
    {
        $datos = $request->validate([
            'estado' => ['required', Rule::in(['CANCELADA', 'NO_ASISTIO'])],
            'motivo' => ['required_if:estado,CANCELADA', 'nullable', 'string', 'max:255'],
        ], ['motivo.required_if' => 'Indica el motivo de la cancelación.']);

        return $this->ejecutar(
            fn () => $this->citaServicio->cambiarEstado((int) $id, $datos['estado'], $datos['motivo'] ?? null),
            $datos['estado'] === 'CANCELADA' ? 'Cita cancelada.' : 'Inasistencia registrada.',
            'Error al actualizar la cita.'
        );
    }
}
