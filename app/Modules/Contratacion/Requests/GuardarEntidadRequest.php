<?php

namespace App\Modules\Contratacion\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use App\Modules\Contratacion\Models\Entidad;
use App\Modules\Red\Rules\DigitoVerificacionNit;
use Illuminate\Validation\Rule;

class GuardarEntidadRequest extends BaseApiRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('codigo_minsalud') && $this->input('codigo_minsalud') !== null) {
            $this->merge(['codigo_minsalud' => strtoupper(trim((string) $this->input('codigo_minsalud')))]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('id');
        $requerido = $id ? 'sometimes' : 'required';
        $nit = $this->input('nit') ?? ($id ? Entidad::withTrashed()->find($id)?->nit : null);

        return [
            'nit' => [$requerido, 'string', 'regex:/^\d{6,15}$/', Rule::unique('entidades', 'nit')->ignore($id)->whereNull('deleted_at')],
            'digito_verificacion' => [$requerido, 'required_with:nit', 'digits:1', new DigitoVerificacionNit($nit)],
            'razon_social' => [$requerido, 'string', 'max:200'],
            'sigla' => ['nullable', 'string', 'max:30'],
            'codigo_minsalud' => ['nullable', 'string', 'regex:/^[A-Z0-9]{3,10}$/', Rule::unique('entidades', 'codigo_minsalud')->ignore($id)->whereNull('deleted_at')],
            'tipo' => [$requerido, Rule::in(Entidad::TIPOS)],
            'regimen_ids' => ['nullable', 'array'],
            'regimen_ids.*' => ['integer', 'distinct', Rule::exists('regimenes', 'id')->whereNull('deleted_at')],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:150'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nit.required' => 'El NIT es obligatorio.',
            'nit.regex' => 'El NIT debe tener entre 6 y 15 dígitos, sin puntos ni dígito de verificación.',
            'nit.unique' => 'Ya existe una entidad con este NIT.',
            'digito_verificacion.required' => 'El dígito de verificación es obligatorio.',
            'digito_verificacion.required_with' => 'Si cambias el NIT, envía también su dígito de verificación.',
            'digito_verificacion.digits' => 'El dígito de verificación es un solo número.',
            'razon_social.required' => 'La razón social es obligatoria.',
            'codigo_minsalud.regex' => 'El código MinSalud tiene entre 3 y 10 letras o números, p. ej. EPS037.',
            'codigo_minsalud.unique' => 'Ya existe una entidad con este código MinSalud.',
            'tipo.required' => 'Selecciona el tipo de entidad.',
            'tipo.in' => 'El tipo de entidad no es válido.',
            'correo.email' => 'El correo no tiene un formato válido.',
        ];
    }
}
