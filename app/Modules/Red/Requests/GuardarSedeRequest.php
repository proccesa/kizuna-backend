<?php

namespace App\Modules\Red\Requests;

use App\Modules\Common\Requests\BaseApiRequest;
use App\Modules\Red\Models\Sede;
use Illuminate\Validation\Rule;

/**
 * Validación para crear (POST /prestadores/{id}/sedes) y actualizar (PUT /sedes/{id}) sedes.
 */
class GuardarSedeRequest extends BaseApiRequest
{
    /**
     * Al editar solo una de las horas, se completa con la guardada para validar el rango.
     */
    protected function prepareForValidation(): void
    {
        $sedeId = $this->route('sedeId');
        if ($sedeId === null || ($this->has('hora_apertura') && $this->has('hora_cierre'))) {
            return;
        }
        if (! $this->has('hora_apertura') && ! $this->has('hora_cierre')) {
            return;
        }

        $sede = Sede::withTrashed()->find($sedeId);
        if ($sede) {
            $this->merge([
                'hora_apertura' => $this->input('hora_apertura', $sede->hora_apertura),
                'hora_cierre' => $this->input('hora_cierre', $sede->hora_cierre),
            ]);
        }
    }

    public function rules(): array
    {
        $sedeId = $this->route('sedeId');
        $esEdicion = $sedeId !== null;
        $requerido = $esEdicion ? 'sometimes' : 'required';
        $prestadorId = $esEdicion ? Sede::withTrashed()->find($sedeId)?->prestador_id : $this->route('id');

        return [
            'numero_sede' => [
                $requerido,
                'string',
                'regex:/^\d{2}$/',
                Rule::unique('sedes', 'numero_sede')
                    ->where('prestador_id', $prestadorId)
                    ->whereNull('deleted_at')
                    ->ignore($sedeId),
            ],
            'nombre' => [$requerido, 'string', 'max:150'],
            'municipio_id' => [$requerido, 'integer', Rule::exists('municipios', 'id')->where('activo', true)],
            'direccion' => [$requerido, 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:150'],
            'consultorios' => ['nullable', 'integer', 'min:0', 'max:999'],
            'dias_atencion' => [$requerido, 'array', 'min:1'],
            'dias_atencion.*' => ['integer', 'distinct', 'between:1,7'],
            'hora_apertura' => [$requerido, 'date_format:H:i'],
            'hora_cierre' => [$requerido, 'date_format:H:i', 'after:hora_apertura'],
            'es_principal' => ['nullable', 'boolean'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'numero_sede.required' => 'El número de sede es obligatorio.',
            'numero_sede.regex' => 'El número de sede son dos dígitos: 01, 02…',
            'numero_sede.unique' => 'El prestador ya tiene una sede con este número.',
            'nombre.required' => 'El nombre de la sede es obligatorio.',
            'municipio_id.required' => 'El municipio es obligatorio.',
            'municipio_id.exists' => 'El municipio seleccionado no existe.',
            'direccion.required' => 'La dirección es obligatoria.',
            'dias_atencion.required' => 'Selecciona al menos un día de atención.',
            'dias_atencion.min' => 'Selecciona al menos un día de atención.',
            'dias_atencion.*.between' => 'Los días de atención van de 1 (lunes) a 7 (domingo).',
            'hora_apertura.required' => 'La hora de apertura es obligatoria.',
            'hora_apertura.date_format' => 'La hora de apertura debe tener el formato HH:MM.',
            'hora_cierre.required' => 'La hora de cierre es obligatoria.',
            'hora_cierre.date_format' => 'La hora de cierre debe tener el formato HH:MM.',
            'hora_cierre.after' => 'La hora de cierre debe ser posterior a la de apertura.',
            'correo.email' => 'El correo no tiene un formato válido.',
        ];
    }
}
