<?php

namespace App\Modules\HistoriaClinica\Controllers;

use App\Modules\Common\Controllers\ControladorBase;
use App\Modules\HistoriaClinica\Services\HistoriaServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistoriaControlador extends ControladorBase
{
    public function __construct(
        protected HistoriaServicio $historiaServicio
    ) {}

    public function plantillas(): JsonResponse
    {
        return $this->ejecutar(fn () => $this->historiaServicio->plantillas(), 'Plantillas de historia clínica.', 'Error al obtener las plantillas.');
    }

    public function index(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->historiaServicio->listar($request->only(['estado', 'origen', 'plantilla', 'buscar', 'paciente_id', 'especialista_id', 'orden_id']), (int) $request->input('por_pagina', 20)),
            'Historias clínicas obtenidas exitosamente.',
            'Error al obtener las historias clínicas.'
        );
    }

    public function show(Request $request, int|string $id): JsonResponse
    {
        return $this->ejecutar(function () use ($request, $id) {
            $historia = $this->historiaServicio->obtenerPorId((int) $id);
            $this->historiaServicio->eventoLectura($historia->id, $request->user()?->id, $request->ip());

            return $historia;
        }, 'Historia clínica obtenida.', 'Error al consultar la historia clínica.');
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'cita_id' => ['nullable', 'integer', 'exists:citas,id'],
            'paciente_id' => ['nullable', 'integer', 'exists:pacientes,id'],
            'orden_id' => ['nullable', 'integer', 'exists:ordenes_quirurgicas,id'],
            'plantilla' => ['nullable', 'string', 'exists:plantillas_hc,codigo'],
        ]);

        return $this->ejecutar(fn () => $this->historiaServicio->crear($datos, $request->user()?->id, $request->ip()), 'Historia clínica abierta.', 'Error al abrir la historia clínica.', 201);
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        $datos = $request->validate(['respuestas' => ['present', 'array']]);

        return $this->ejecutar(fn () => $this->historiaServicio->guardar((int) $id, $datos['respuestas'], $request->user()?->id, $request->ip()), 'Borrador guardado.', 'Error al guardar la historia clínica.');
    }

    public function finalizar(Request $request, int|string $id): JsonResponse
    {
        $datos = $request->validate(['respuestas' => ['nullable', 'array']]);

        return $this->ejecutar(fn () => $this->historiaServicio->finalizar((int) $id, $datos['respuestas'] ?? null, $request->user()?->id, $request->ip()), 'Historia clínica finalizada.', 'Error al finalizar la historia clínica.');
    }

    public function anular(Request $request, int|string $id): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']], [
            'motivo.required' => 'Indica por qué se anula la historia.',
            'motivo.min' => 'Describe el motivo con al menos 10 caracteres.',
        ]);

        return $this->ejecutar(fn () => $this->historiaServicio->anular((int) $id, $datos['motivo'], $request->user()?->id, $request->ip()), 'Historia clínica anulada.', 'Error al anular la historia clínica.');
    }
}
