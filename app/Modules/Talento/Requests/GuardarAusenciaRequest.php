<?php

namespace App\Modules\Talento\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use App\Modules\Talento\Models\Ausencia;
use Illuminate\Validation\Rule;

class GuardarAusenciaRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(Ausencia::TIPOS)],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo.required' => 'Selecciona el tipo de novedad.',
            'tipo.in' => 'El tipo de novedad no es válido.',
            'fecha_inicio.required' => 'La fecha de inicio es obligatoria.',
            'fecha_fin.required' => 'La fecha de fin es obligatoria.',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio.',
        ];
    }
}
