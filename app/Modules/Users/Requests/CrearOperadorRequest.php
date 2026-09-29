<?php

namespace App\Modules\Users\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class CrearOperadorRequest extends BaseApiRequest
{
    /**
     * Reglas de validación para la creación directa de un operador.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'unique:operadores,user_id'],
            'tipo_documento_id' => ['required', 'integer', 'exists:tipos_documento,id'],
            'documento' => ['required', 'string', 'max:30', Rule::unique('operadores', 'documento')->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
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
            'tipo_documento_id.required' => 'El tipo de documento es obligatorio.',
            'tipo_documento_id.exists' => 'El tipo de documento seleccionado no existe.',
            'documento.required' => 'El número de documento es obligatorio.',
            'documento.unique' => 'El número de documento ya se encuentra registrado.',
            'nombre.required' => 'El nombre es obligatorio.',
            'apellido.required' => 'El apellido es obligatorio.',
            'user_id.unique' => 'El usuario ya tiene un operador asignado.',
        ];
    }
}
