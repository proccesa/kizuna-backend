<?php

namespace App\Modules\Cirugia\Requests;

use App\Modules\Cirugia\Models\OrdenQuirurgica;
use App\Modules\Common\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

/**
 * Datos de una orden quirúrgica. Las mismas reglas aplican al registro manual,
 * al endpoint de integración y a cada fila del CSV.
 */
class GuardarOrdenRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return self::reglas($this->is('api/v1/integracion/*'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function reglas(bool $referenciaObligatoria = false): array
    {
        return [
            'paciente' => ['required', 'array'],
            'paciente.tipo_documento' => ['required', 'string', Rule::exists('tipos_documento', 'codigo')->where('activo', true)],
            'paciente.numero_documento' => ['required', 'string', 'regex:/^[A-Za-z0-9.\s]{3,25}$/'],
            'paciente.primer_nombre' => ['required', 'string', 'max:60'],
            'paciente.segundo_nombre' => ['nullable', 'string', 'max:60'],
            'paciente.primer_apellido' => ['required', 'string', 'max:60'],
            'paciente.segundo_apellido' => ['nullable', 'string', 'max:60'],
            'paciente.fecha_nacimiento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'paciente.sexo' => ['required', Rule::in(['F', 'M', 'I', 'f', 'm', 'i'])],
            'paciente.telefono' => ['nullable', 'string', 'max:30'],
            'paciente.correo' => ['nullable', 'email', 'max:150'],
            'paciente.direccion' => ['nullable', 'string', 'max:255'],
            'paciente.municipio' => ['nullable', 'string', 'regex:/^\d{4,5}$/'],
            'cups' => ['required', 'string', Rule::exists('cups', 'codigo')->where('habilitado', true)],
            'contrato_id' => ['nullable', 'integer', Rule::exists('contratos', 'id')->whereNull('deleted_at')],
            'contrato_numero' => ['nullable', 'string', Rule::exists('contratos', 'numero')->whereNull('deleted_at')],
            'diagnostico_cie10' => ['nullable', 'string', 'regex:/^[A-Za-z]\d{2}[A-Za-z0-9]{0,2}$/'],
            'diagnostico' => ['nullable', 'string', 'max:255'],
            'prioridad' => ['nullable', Rule::in([...OrdenQuirurgica::PRIORIDADES, 'electiva', 'prioritaria'])],
            'fecha_orden' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'medico_ordenante' => ['nullable', 'string', 'max:150'],
            // Cirujano que realizará la cirugía (documento del especialista en Kizuna). Si no viene, lo asigna el motor.
            'cirujano_documento' => ['nullable', 'string', 'max:20'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'referencia_externa' => [$referenciaObligatoria ? 'required' : 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return self::mensajes();
    }

    /**
     * @return array<string, string>
     */
    public static function mensajes(): array
    {
        return [
            'paciente.required' => 'Faltan los datos del paciente.',
            'paciente.tipo_documento.required' => 'Falta el tipo de documento del paciente.',
            'paciente.tipo_documento.exists' => 'El tipo de documento del paciente no existe.',
            'paciente.numero_documento.required' => 'Falta el número de documento del paciente.',
            'paciente.numero_documento.regex' => 'El documento del paciente admite entre 3 y 25 letras o números.',
            'paciente.primer_nombre.required' => 'Falta el primer nombre del paciente.',
            'paciente.primer_apellido.required' => 'Falta el primer apellido del paciente.',
            'paciente.fecha_nacimiento.required' => 'Falta la fecha de nacimiento del paciente.',
            'paciente.fecha_nacimiento.date_format' => 'La fecha de nacimiento debe tener el formato AAAA-MM-DD.',
            'paciente.fecha_nacimiento.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
            'paciente.sexo.required' => 'Falta el sexo del paciente.',
            'paciente.sexo.in' => 'El sexo debe ser F, M o I.',
            'paciente.correo.email' => 'El correo del paciente no es válido.',
            'paciente.municipio.regex' => 'El municipio es el código DIVIPOLA de 5 dígitos.',
            'cups.required' => 'Falta el CUPS de la cirugía.',
            'cups.exists' => 'El CUPS no existe o no está habilitado.',
            'contrato_numero.exists' => 'El contrato no existe.',
            'diagnostico_cie10.regex' => 'El diagnóstico CIE-10 tiene el formato K802 o K80.2 sin punto.',
            'prioridad.in' => 'La prioridad debe ser ELECTIVA o PRIORITARIA.',
            'fecha_orden.before_or_equal' => 'La fecha de la orden no puede ser futura.',
            'referencia_externa.required' => 'Envía la referencia de la orden en tu sistema (referencia_externa).',
        ];
    }
}
