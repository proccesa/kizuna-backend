<?php

namespace App\Modules\Talento\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use App\Modules\Talento\Models\Especialista;
use Illuminate\Validation\Rule;

/**
 * Validación para crear (POST /especialistas) y actualizar (PUT /especialistas/{id}).
 */
class GuardarEspecialistaRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('numero_documento')) {
            $this->merge(['numero_documento' => preg_replace('/[\s.]/', '', (string) $this->input('numero_documento'))]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('id');
        $requerido = $id ? 'sometimes' : 'required';
        $tipoDocumentoId = $this->input('tipo_documento_id', $id ? Especialista::withTrashed()->find($id)?->tipo_documento_id : null);

        return [
            'tipo_documento_id' => [$requerido, 'integer', Rule::exists('tipos_documento', 'id')->where('activo', true)->whereNull('deleted_at')],
            'numero_documento' => [
                $requerido,
                'string',
                'regex:/^[A-Za-z0-9]{3,20}$/',
                Rule::unique('especialistas', 'numero_documento')
                    ->where('tipo_documento_id', $tipoDocumentoId)
                    ->whereNull('deleted_at')
                    ->ignore($id),
            ],
            'nombres' => [$requerido, 'string', 'max:100'],
            'apellidos' => [$requerido, 'string', 'max:100'],
            'registro_profesional' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'especialidad_ids' => [$requerido, 'array', 'min:1'],
            'especialidad_ids.*' => ['integer', 'distinct', Rule::exists('especialidades', 'id')->whereNull('deleted_at')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at'), Rule::unique('especialistas', 'user_id')->ignore($id)],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo_documento_id.required' => 'El tipo de documento es obligatorio.',
            'tipo_documento_id.exists' => 'El tipo de documento no existe.',
            'numero_documento.required' => 'El número de documento es obligatorio.',
            'numero_documento.regex' => 'El documento admite entre 3 y 20 letras o números, sin puntos ni espacios.',
            'numero_documento.unique' => 'Ya existe un especialista con este documento.',
            'nombres.required' => 'Los nombres son obligatorios.',
            'apellidos.required' => 'Los apellidos son obligatorios.',
            'correo.email' => 'El correo no tiene un formato válido.',
            'especialidad_ids.required' => 'Selecciona al menos una especialidad.',
            'especialidad_ids.min' => 'Selecciona al menos una especialidad.',
            'especialidad_ids.*.exists' => 'Una de las especialidades seleccionadas no existe.',
            'user_id.unique' => 'Esa cuenta ya está vinculada a otro especialista.',
        ];
    }
}
