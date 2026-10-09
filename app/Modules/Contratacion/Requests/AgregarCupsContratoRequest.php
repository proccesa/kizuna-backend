<?php

namespace App\Modules\Contratacion\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class AgregarCupsContratoRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'cups_ids' => ['required', 'array', 'min:1', 'max:500'],
            'cups_ids.*' => ['integer', 'distinct', Rule::exists('cups', 'id')->where('habilitado', true)],
            'cantidad' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'tarifa' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'cups_ids.required' => 'Selecciona al menos un CUPS.',
            'cups_ids.min' => 'Selecciona al menos un CUPS.',
            'cups_ids.*.exists' => 'Uno de los CUPS no existe o no está habilitado.',
            'cantidad.min' => 'La cantidad debe ser mayor que cero.',
            'tarifa.min' => 'La tarifa no puede ser negativa.',
        ];
    }
}
