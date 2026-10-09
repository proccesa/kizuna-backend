<?php

namespace App\Modules\Contratacion\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class GuardarPoblacionRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $requerido = $this->route('id') ? 'sometimes' : 'required';

        return [
            'contrato_id' => [$requerido, 'integer', Rule::exists('contratos', 'id')->whereNull('deleted_at')],
            'nombre' => [$requerido, 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'contrato_id.required' => 'Selecciona el contrato.',
            'contrato_id.exists' => 'El contrato no existe.',
            'nombre.required' => 'El nombre de la población es obligatorio.',
        ];
    }
}
