<?php

namespace App\Modules\Contratacion\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use App\Modules\Contratacion\Models\Contrato;
use Illuminate\Validation\Rule;

class GuardarContratoRequest extends BaseApiRequest
{
    /**
     * Al editar solo una de las fechas, se completa con la guardada para validar el rango.
     */
    protected function prepareForValidation(): void
    {
        $id = $this->route('id');
        if ($id && ($this->has('fecha_inicio') xor $this->has('fecha_fin'))) {
            $contrato = Contrato::withTrashed()->find($id);
            if ($contrato) {
                $this->merge([
                    'fecha_inicio' => $this->input('fecha_inicio', $contrato->fecha_inicio?->toDateString()),
                    'fecha_fin' => $this->input('fecha_fin', $contrato->fecha_fin?->toDateString()),
                ]);
            }
        }
    }

    public function rules(): array
    {
        $id = $this->route('id');
        $requerido = $id ? 'sometimes' : 'required';
        $entidadId = $this->input('entidad_id', $id ? Contrato::withTrashed()->find($id)?->entidad_id : null);

        return [
            'entidad_id' => [$requerido, 'integer', Rule::exists('entidades', 'id')->whereNull('deleted_at')],
            'numero' => [
                $requerido,
                'string',
                'max:50',
                Rule::unique('contratos', 'numero')->where('entidad_id', $entidadId)->whereNull('deleted_at')->ignore($id),
            ],
            'modalidad_contratacion_id' => [$requerido, 'integer', Rule::exists('modalidades_contratacion', 'id')->whereNull('deleted_at')],
            'regimen_id' => [$requerido, 'integer', Rule::exists('regimenes', 'id')->whereNull('deleted_at')],
            'fecha_inicio' => [$requerido, 'date_format:Y-m-d'],
            'fecha_fin' => [$requerido, 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'valor' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'objeto' => ['nullable', 'string', 'max:500'],
            'sede_ids' => ['nullable', 'array'],
            'sede_ids.*' => ['integer', 'distinct', Rule::exists('sedes', 'id')->whereNull('deleted_at')],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'entidad_id.required' => 'Selecciona la entidad.',
            'entidad_id.exists' => 'La entidad no existe.',
            'numero.required' => 'El número del contrato es obligatorio.',
            'numero.unique' => 'La entidad ya tiene un contrato con este número.',
            'modalidad_contratacion_id.required' => 'Selecciona la modalidad.',
            'regimen_id.required' => 'Selecciona el régimen.',
            'fecha_inicio.required' => 'La fecha de inicio es obligatoria.',
            'fecha_fin.required' => 'La fecha de fin es obligatoria.',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio.',
            'valor.numeric' => 'El valor debe ser un número.',
            'valor.min' => 'El valor no puede ser negativo.',
        ];
    }
}
