<?php

namespace App\Modules\Contratacion\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class CargarPoblacionRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'max:30720', 'mimes:csv,txt'],
            'simular' => ['nullable', 'boolean'],
            'modo' => ['nullable', Rule::in(['REEMPLAZAR', 'AGREGAR'])],
        ];
    }

    public function messages(): array
    {
        return [
            'archivo.required' => 'Selecciona el archivo CSV.',
            'archivo.mimes' => 'El archivo debe ser CSV (en Excel: Guardar como → CSV UTF-8).',
            'archivo.max' => 'El archivo no puede pesar más de 30 MB.',
            'modo.in' => 'El modo de cargue debe ser REEMPLAZAR o AGREGAR.',
        ];
    }
}
