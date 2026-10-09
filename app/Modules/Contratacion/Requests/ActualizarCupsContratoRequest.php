<?php

namespace App\Modules\Contratacion\Requests;

use App\Modules\Common\Requests\BaseApiRequest;

class ActualizarCupsContratoRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'cantidad' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'tarifa' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'cantidad.min' => 'La cantidad debe ser mayor que cero.',
            'tarifa.min' => 'La tarifa no puede ser negativa.',
        ];
    }
}
