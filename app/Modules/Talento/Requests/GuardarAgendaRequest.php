<?php

namespace App\Modules\Talento\Requests;

use App\Modules\Common\Requests\BaseApiRequest;

/**
 * Validación de forma de una franja de agenda. Las reglas de negocio
 * (horario de la sede, especialidad del profesional, cruces) están en AgendaServicio.
 */
class GuardarAgendaRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $requerido = $this->route('agendaId') ? 'sometimes' : 'required';

        return [
            'sede_id' => [$requerido, 'integer', 'exists:sedes,id'],
            'especialidad_id' => [$requerido, 'integer', 'exists:especialidades,id'],
            'dias' => [$requerido, 'array', 'min:1'],
            'dias.*' => ['integer', 'distinct', 'between:1,7'],
            'hora_inicio' => [$requerido, 'date_format:H:i'],
            'hora_fin' => [$requerido, 'date_format:H:i'],
            'vigente_desde' => [$requerido, 'date_format:Y-m-d'],
            'vigente_hasta' => ['nullable', 'date_format:Y-m-d'],
            'consultorio' => ['nullable', 'string', 'max:30'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'sede_id.required' => 'Selecciona la sede.',
            'especialidad_id.required' => 'Selecciona la especialidad.',
            'dias.required' => 'Selecciona al menos un día.',
            'dias.min' => 'Selecciona al menos un día.',
            'dias.*.between' => 'Los días van de 1 (lunes) a 7 (domingo).',
            'hora_inicio.required' => 'La hora de inicio es obligatoria.',
            'hora_inicio.date_format' => 'La hora de inicio debe tener el formato HH:MM.',
            'hora_fin.required' => 'La hora de fin es obligatoria.',
            'hora_fin.date_format' => 'La hora de fin debe tener el formato HH:MM.',
            'vigente_desde.required' => 'Indica desde cuándo rige la agenda.',
            'vigente_desde.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'vigente_hasta.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
        ];
    }
}
