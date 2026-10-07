<?php

namespace App\Modules\Servicios\Requests;

use App\Modules\Common\Requests\BaseApiRequest;

class ActualizarPortafolioRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'duracion_minutos' => ['sometimes', 'required', 'integer', 'min:5', 'max:480'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'duracion_minutos.min' => 'La duración mínima es de 5 minutos.',
            'duracion_minutos.max' => 'La duración máxima es de 480 minutos (8 horas).',
        ];
    }
}
