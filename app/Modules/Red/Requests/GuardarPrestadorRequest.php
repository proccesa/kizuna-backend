<?php

namespace App\Modules\Red\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use App\Modules\Red\Models\Prestador;
use App\Modules\Red\Rules\DigitoVerificacionNit;
use Illuminate\Validation\Rule;

/**
 * Validación para crear (POST) y actualizar (PUT) prestadores.
 * Al actualizar, todos los campos son opcionales (`sometimes`).
 */
class GuardarPrestadorRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $id = $this->route('id');
        $requerido = $id ? 'sometimes' : 'required';
        $nit = $this->input('nit') ?? ($id ? Prestador::withTrashed()->find($id)?->nit : null);

        return [
            'nit' => [
                $requerido,
                'string',
                'regex:/^\d{6,15}$/',
                Rule::unique('prestadores', 'nit')->ignore($id)->whereNull('deleted_at'),
            ],
            'digito_verificacion' => [$requerido, 'required_with:nit', 'digits:1', new DigitoVerificacionNit($nit)],
            'razon_social' => [$requerido, 'string', 'max:200'],
            'nombre_comercial' => ['nullable', 'string', 'max:200'],
            'codigo_habilitacion' => [
                'nullable',
                'string',
                'regex:/^\d{10,12}$/',
                Rule::unique('prestadores', 'codigo_habilitacion')->ignore($id)->whereNull('deleted_at'),
            ],
            'naturaleza' => [$requerido, Rule::in(Prestador::NATURALEZAS)],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:150'],
            'representante_legal' => ['nullable', 'string', 'max:150'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nit.required' => 'El NIT es obligatorio.',
            'nit.regex' => 'El NIT debe tener entre 6 y 15 dígitos, sin puntos ni dígito de verificación.',
            'nit.unique' => 'Ya existe un prestador con este NIT.',
            'digito_verificacion.required' => 'El dígito de verificación es obligatorio.',
            'digito_verificacion.required_with' => 'Si cambias el NIT, envía también su dígito de verificación.',
            'digito_verificacion.digits' => 'El dígito de verificación es un solo número.',
            'razon_social.required' => 'La razón social es obligatoria.',
            'codigo_habilitacion.regex' => 'El código de habilitación debe tener entre 10 y 12 dígitos.',
            'codigo_habilitacion.unique' => 'Ya existe un prestador con este código de habilitación.',
            'naturaleza.required' => 'La naturaleza jurídica es obligatoria.',
            'naturaleza.in' => 'La naturaleza jurídica debe ser privada, pública o mixta.',
            'correo.email' => 'El correo no tiene un formato válido.',
        ];
    }
}
