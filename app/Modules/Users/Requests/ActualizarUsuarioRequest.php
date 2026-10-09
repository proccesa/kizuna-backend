<?php

namespace App\Modules\Users\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use App\Modules\Users\Models\Operador;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class ActualizarUsuarioRequest extends BaseApiRequest
{
    /**
     * Reglas de validación para actualizar un usuario.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('id') ?? $this->route('usuario');

        return [
            // Datos del usuario
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId)->whereNull('deleted_at'),
            ],
            'password' => ['nullable', 'string', 'min:8'],
            'activo' => ['nullable', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],

            // Datos del operador asociado
            'operador' => ['nullable', 'array'],
            'operador.tipo_documento_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:tipos_documento,id',
            ],
            'operador.documento' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                // Validación única excluyendo el operador actual si existe
                function ($attribute, $value, $fail) use ($userId) {
                    $existe = Operador::where('documento', $value)
                        ->where('user_id', '!=', $userId)
                        ->whereNull('deleted_at')
                        ->exists();

                    if ($existe) {
                        $fail('El número de documento ya está en uso por otro operador.');
                    }
                },
            ],
            'operador.nombre' => ['sometimes', 'required', 'string', 'max:100'],
            'operador.apellido' => ['sometimes', 'required', 'string', 'max:100'],
            'operador.telefono' => ['nullable', 'string', 'max:30'],
            'operador.direccion' => ['nullable', 'string', 'max:255'],
            'operador.activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Mensajes personalizados de error.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del usuario es obligatorio.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico no es válido.',
            'email.unique' => 'Este correo electrónico ya se encuentra registrado.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'operador.tipo_documento_id.exists' => 'El tipo de documento seleccionado no es válido.',
            'roles.*.exists' => 'Uno o más roles no existen en el sistema.',
        ];
    }
}
