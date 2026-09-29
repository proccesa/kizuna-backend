<?php

namespace App\Modules\Users\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class ActualizarOperadorRequest extends BaseApiRequest
{
    /**
     * Reglas de validación para actualizar un operador.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $operadorId = $this->route('id') ?? $this->route('operadore') ?? $this->route('operador');

        return [
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                Rule::unique('operadores', 'user_id')->ignore($operadorId)->whereNull('deleted_at'),
            ],
            'tipo_documento_id' => ['sometimes', 'required', 'integer', 'exists:tipos_documento,id'],
            'documento' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                Rule::unique('operadores', 'documento')->ignore($operadorId)->whereNull('deleted_at'),
            ],
            'nombre' => ['sometimes', 'required', 'string', 'max:100'],
            'apellido' => ['sometimes', 'required', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Mensajes personalizados de validación.
     */
    public function messages(): array
    {
        return [
            'tipo_documento_id.exists' => 'El tipo de documento seleccionado no existe.',
            'documento.unique' => 'El número de documento ya se encuentra registrado.',
            'user_id.unique' => 'El usuario ya tiene un operador asignado.',
        ];
    }
}
