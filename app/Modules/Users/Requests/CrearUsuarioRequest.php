<?php

namespace App\Modules\Users\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class CrearUsuarioRequest extends BaseApiRequest
{
    /**
     * Reglas de validación para la creación de un usuario.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Datos de la cuenta de usuario
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'activo' => ['nullable', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],

            // Datos del operador asociado (opcionales al crear el usuario)
            'operador' => ['nullable', 'array'],
            'operador.tipo_documento_id' => [
                'required_with:operador',
                'integer',
                'exists:tipos_documento,id',
            ],
            'operador.documento' => [
                'required_with:operador',
                'string',
                'max:30',
                Rule::unique('operadores', 'documento')->whereNull('deleted_at'),
            ],
            'operador.nombre' => ['required_with:operador', 'string', 'max:100'],
            'operador.apellido' => ['required_with:operador', 'string', 'max:100'],
            'operador.telefono' => ['nullable', 'string', 'max:30'],
            'operador.direccion' => ['nullable', 'string', 'max:255'],
            'operador.activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Mensajes personalizados de error de validación.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del usuario es obligatorio.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico no tiene un formato válido.',
            'email.unique' => 'Este correo electrónico ya se encuentra registrado.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'operador.tipo_documento_id.required_with' => 'El tipo de documento es obligatorio para el operador.',
            'operador.tipo_documento_id.exists' => 'El tipo de documento seleccionado no es válido.',
            'operador.documento.required_with' => 'El número de documento es obligatorio para el operador.',
            'operador.documento.unique' => 'El número de documento ya se encuentra registrado para otro operador.',
            'operador.nombre.required_with' => 'El nombre del operador es obligatorio.',
            'operador.apellido.required_with' => 'El apellido del operador es obligatorio.',
            'roles.*.exists' => 'Uno o más roles especificados no existen.',
        ];
    }
}
