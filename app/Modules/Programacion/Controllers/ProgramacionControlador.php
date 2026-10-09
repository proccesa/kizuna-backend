<?php

namespace App\Modules\Programacion\Controllers;

use App\Modules\Common\Controllers\ControladorBase;
use App\Modules\Programacion\Services\MotorProgramacion;
use App\Modules\Programacion\Services\ProgramacionServicio;
use App\Modules\Programacion\Services\ReglasProgramacion;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgramacionControlador extends ControladorBase
{
    public function __construct(
        protected MotorProgramacion $motor,
        protected ProgramacionServicio $programacion,
        protected ReglasProgramacion $reglas
    ) {}

    public function resumen(): JsonResponse
    {
        return $this->ejecutar(fn () => $this->programacion->resumen(), 'Resumen de la programación.', 'Error al obtener el resumen.');
    }

    public function cola(): JsonResponse
    {
        return $this->ejecutar(fn () => $this->programacion->cola(), 'Pacientes por programar.', 'Error al obtener la cola.');
    }

    public function propuesta(): JsonResponse
    {
        return $this->ejecutar(fn () => $this->programacion->propuesta(), 'Propuesta pendiente.', 'Error al obtener la propuesta.');
    }

    public function programa(Request $request): JsonResponse
    {
        $datos = $request->validate(['desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'], 'sede_id' => ['nullable', 'integer']]);

        return $this->ejecutar(fn () => $this->programacion->programa($datos['desde'], $datos['hasta'], $datos['sede_id'] ?? null), 'Programa quirúrgico.', 'Error al obtener el programa.');
    }

    /**
     * Ejecuta el motor y deja una propuesta para aprobar.
     */
    public function generar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde', 'before_or_equal:'.now()->addDays(90)->toDateString()],
            'sede_ids' => ['nullable', 'array'],
            'sede_ids.*' => ['integer', 'exists:sedes,id'],
        ], ['desde.after_or_equal' => 'La programación no puede empezar antes de hoy.', 'hasta.before_or_equal' => 'El periodo puede ser de hasta 90 días.', 'hasta.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.']);

        return $this->ejecutar(function () use ($datos, $request) {
            $this->motor->generar(Carbon::parse($datos['desde']), Carbon::parse($datos['hasta']), $datos['sede_ids'] ?? null, $request->user()?->id);

            return $this->programacion->propuesta();
        }, 'Propuesta de programación generada.', 'Error al generar la programación.', 201);
    }

    public function aprobar(Request $request, int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->programacion->aprobar((int) $id, $request->user()?->id), 'Programa quirúrgico aprobado.', 'Error al aprobar la propuesta.');
    }

    public function descartar(int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->programacion->descartar((int) $id), 'Propuesta descartada. Los recursos quedaron libres.', 'Error al descartar la propuesta.');
    }

    public function rechazar(Request $request, int|string $id): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:255']], ['motivo.required' => 'Indica el motivo.']);

        return $this->ejecutar(fn () => $this->programacion->rechazar((int) $id, $datos['motivo']), 'Cirugía quitada de la propuesta.', 'Error al quitar la cirugía.');
    }

    public function cancelar(Request $request, int|string $id): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:255']], ['motivo.required' => 'Indica el motivo.']);

        return $this->ejecutar(fn () => $this->programacion->cancelar((int) $id, $datos['motivo']), 'Cirugía cancelada. El paciente volvió a la cola.', 'Error al cancelar la cirugía.');
    }

    public function realizar(Request $request, int|string $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->programacion->realizar((int) $id, $request->user()?->id), 'Cirugía realizada. Se descontaron los insumos.', 'Error al registrar la cirugía.');
    }

    public function reglas(): JsonResponse
    {
        return $this->ejecutar(fn () => $this->reglas->todas(), 'Reglas de programación.', 'Error al obtener las reglas.');
    }

    public function guardarReglas(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'anticipacion_dias' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'rotacion_minutos' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'paso_minutos' => ['sometimes', 'integer', 'in:15,20,30,60'],
            'hora_reservada_temprana' => ['sometimes', 'date_format:H:i'],
            'pesos' => ['sometimes', 'array'],
            'pesos.*' => ['integer', 'min:0', 'max:100000'],
        ]);

        return $this->ejecutar(fn () => $this->reglas->guardar($datos), 'Reglas de programación actualizadas.', 'Error al guardar las reglas.');
    }
}
