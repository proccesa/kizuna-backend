<?php

namespace App\Modules\Integracion\Controllers;

use App\Modules\Cirugia\Models\OrdenQuirurgica;
use App\Modules\Cirugia\Requests\GuardarOrdenRequest;
use App\Modules\Cirugia\Services\OrdenServicio;
use App\Modules\Common\Controllers\ControladorBase;
use App\Modules\HistoriaClinica\Services\HistoriaServicio;
use App\Modules\Integracion\Requests\RecibirHistoriaRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints para sistemas externos. Cada sistema usa su propio token (clientes de integración).
 */
class IntegracionControlador extends ControladorBase
{
    public function __construct(
        protected OrdenServicio $ordenServicio,
        protected HistoriaServicio $historiaServicio
    ) {}

    /**
     * Recibe una orden quirúrgica. Es idempotente por `referencia_externa`: reenviarla no la duplica.
     */
    public function crearOrden(GuardarOrdenRequest $request): JsonResponse
    {
        $sistema = $request->user()->nombre;
        $resultado = null;

        $respuesta = $this->ejecutar(function () use ($request, $sistema, &$resultado) {
            $resultado = $this->ordenServicio->registrar($request->validated(), 'API', $sistema);

            return $this->resumenOrden($resultado['orden']);
        }, 'Orden recibida.', 'Error al registrar la orden.', 201);

        if ($resultado && $resultado['duplicada']) {
            $respuesta->setStatusCode(200);
            $respuesta->setData(array_merge($respuesta->getData(true), ['mensaje' => 'La orden ya se había recibido con esta referencia.']));
        }

        return $respuesta;
    }

    /**
     * Estado de una orden por su referencia en el sistema externo.
     */
    public function estadoOrden(Request $request, string $referencia): JsonResponse
    {
        return $this->ejecutar(function () use ($request, $referencia) {
            $orden = OrdenQuirurgica::where('sistema_origen', $request->user()->nombre)->where('referencia_externa', $referencia)->first();
            if (! $orden) {
                throw new ModelNotFoundException("No hay una orden con la referencia {$referencia}.");
            }

            return $this->resumenOrden($this->ordenServicio->obtenerPorId($orden->id));
        }, 'Estado de la orden.', 'Error al consultar la orden.');
    }

    /**
     * Recibe el concepto de una valoración pre-anestésica hecha en el sistema externo.
     */
    public function recibirHistoria(RecibirHistoriaRequest $request): JsonResponse
    {
        return $this->ejecutar(function () use ($request) {
            $historia = $this->historiaServicio->recibirExterna($request->validated(), $request->user()->nombre);

            return ['historia_id' => $historia->id, 'orden' => $this->resumenOrden($this->ordenServicio->obtenerPorId($historia->orden_id))];
        }, 'Concepto pre-anestésico recibido.', 'Error al registrar el concepto.', 201);
    }

    /**
     * Respuesta pública de una orden: sin datos clínicos de más.
     */
    private function resumenOrden(OrdenQuirurgica $orden): array
    {
        $cita = $orden->citaActual;

        return [
            'id' => $orden->id,
            'referencia_externa' => $orden->referencia_externa,
            'estado' => $orden->estado,
            'motivo_estado' => $orden->motivo_estado,
            'cups' => $orden->cups?->codigo,
            'especialidad' => $orden->especialidad?->nombre,
            'cita_preanestesia' => $cita ? [
                'fecha' => $cita->fecha->toDateString(),
                'hora_inicio' => $cita->hora_inicio,
                'sede' => $cita->sede?->nombre,
                'anestesiologo' => $cita->especialista?->nombre_completo,
                'estado' => $cita->estado,
            ] : null,
            'concepto' => $orden->concepto,
            'asa' => $orden->asa,
            'aval_hasta' => $orden->aval_hasta?->toDateString(),
            'programable_desde' => $orden->programable_desde?->toDateString(),
        ];
    }
}
