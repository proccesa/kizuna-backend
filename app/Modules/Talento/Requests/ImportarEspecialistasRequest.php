<?php

namespace App\Modules\Talento\Requests;

use App\Modules\Common\Requests\BaseApiRequest;

class ImportarEspecialistasRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
            'simular' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'archivo.required' => 'Selecciona el archivo CSV.',
            'archivo.mimes' => 'El archivo debe ser CSV (en Excel: Guardar como → CSV UTF-8).',
            'archivo.max' => 'El archivo no puede pesar más de 5 MB.',
        ];
    }
}
