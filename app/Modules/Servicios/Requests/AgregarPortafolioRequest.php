<?php

namespace App\Modules\Servicios\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use App\Modules\Red\Models\Sala;
use Illuminate\Validation\Rule;

/**
 * Agrega uno o varios CUPS a una o varias sedes con la misma duración.
 */
class AgregarPortafolioRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'sede_ids' => ['required', 'array', 'min:1', 'max:50'],
            'sede_ids.*' => ['integer', 'distinct', Rule::exists('sedes', 'id')->whereNull('deleted_at')],
            'cups_ids' => ['required', 'array', 'min:1', 'max:200'],
            'cups_ids.*' => ['integer', 'distinct', Rule::exists('cups', 'id')->where('habilitado', true)],
            'duracion_minutos' => ['required', 'integer', 'min:5', 'max:480'],
            'tipo_sala' => ['nullable', Rule::in(Sala::TIPOS)],
        ];
    }

    public function messages(): array
    {
        return [
            'sede_ids.required' => 'Selecciona al menos una sede.',
            'sede_ids.*.exists' => 'Una de las sedes seleccionadas no existe.',
            'cups_ids.required' => 'Selecciona al menos un procedimiento CUPS.',
            'cups_ids.*.exists' => 'Uno de los CUPS no existe o no está habilitado.',
            'duracion_minutos.required' => 'La duración es obligatoria.',
            'duracion_minutos.min' => 'La duración mínima es de 5 minutos.',
            'duracion_minutos.max' => 'La duración máxima es de 480 minutos (8 horas).',
        ];
    }
}
