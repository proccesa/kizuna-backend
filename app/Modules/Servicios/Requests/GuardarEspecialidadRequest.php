<?php

namespace App\Modules\Servicios\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GuardarEspecialidadRequest extends BaseApiRequest
{
    /**
     * El código se genera a partir del nombre si no se envía.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('codigo') && $this->filled('nombre')) {
            $this->merge(['codigo' => Str::slug((string) $this->input('nombre'))]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('id');
        $requerido = $id ? 'sometimes' : 'required';

        return [
            'nombre' => [$requerido, 'string', 'max:150', Rule::unique('especialidades', 'nombre')->ignore($id)->whereNull('deleted_at')],
            'codigo' => [$requerido, 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('especialidades', 'codigo')->ignore($id)->whereNull('deleted_at')],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la especialidad es obligatorio.',
            'nombre.unique' => 'Ya existe una especialidad con este nombre.',
            'codigo.unique' => 'Ya existe una especialidad con este código.',
            'codigo.regex' => 'El código solo admite minúsculas, números y guiones.',
        ];
    }
}
