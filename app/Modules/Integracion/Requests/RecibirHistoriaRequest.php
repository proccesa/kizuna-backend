<?php

namespace App\Modules\Integracion\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class RecibirHistoriaRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'orden_referencia' => ['required_without:orden_id', 'nullable', 'string', 'max:100'],
            'orden_id' => ['required_without:orden_referencia', 'nullable', 'integer'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'fecha_valoracion' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'concepto' => ['required', Rule::in(['APTO', 'APTO_CON_RECOMENDACIONES', 'APLAZADO', 'NO_APTO'])],
            'asa' => ['required_if:concepto,APTO,APTO_CON_RECOMENDACIONES', 'nullable', 'integer', 'between:1,5'],
            'recomendaciones' => ['nullable', 'string', 'max:5000'],
            'motivo' => ['required_if:concepto,APLAZADO,NO_APTO', 'nullable', 'string', 'max:5000'],
            'dias_suspension' => ['nullable', 'integer', 'min:0', 'max:30'],
            'aval_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_valoracion'],
            'anestesiologo' => ['required', 'array'],
            'anestesiologo.nombre' => ['required', 'string', 'max:150'],
            'anestesiologo.registro' => ['nullable', 'string', 'max:30'],
            'respuestas' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'orden_referencia.required_without' => 'Envía orden_referencia (la referencia con que registraste la orden) u orden_id.',
            'fecha_valoracion.required' => 'Envía la fecha de la valoración (AAAA-MM-DD).',
            'concepto.required' => 'Envía el concepto: APTO, APTO_CON_RECOMENDACIONES, APLAZADO o NO_APTO.',
            'concepto.in' => 'El concepto debe ser APTO, APTO_CON_RECOMENDACIONES, APLAZADO o NO_APTO.',
            'asa.required_if' => 'Para un concepto apto envía la clasificación ASA (1 a 5).',
            'motivo.required_if' => 'Para un concepto aplazado o no apto envía el motivo.',
            'anestesiologo.required' => 'Envía los datos del anestesiólogo.',
            'anestesiologo.nombre.required' => 'Envía el nombre del anestesiólogo.',
        ];
    }
}
