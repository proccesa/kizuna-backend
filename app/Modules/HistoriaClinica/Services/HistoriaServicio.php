<?php

namespace App\Modules\HistoriaClinica\Services;

use App\Modules\Cirugia\Models\OrdenQuirurgica;
use App\Modules\Cirugia\Services\OrdenServicio;
use App\Modules\Citas\Models\Cita;
use App\Modules\Contratacion\Models\Paciente;
use App\Modules\HistoriaClinica\Models\HistoriaClinica;
use App\Modules\HistoriaClinica\Models\HistoriaEvento;
use App\Modules\HistoriaClinica\Models\PlantillaHc;
use App\Modules\Talento\Models\Especialista;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HistoriaServicio
{
    private const RELACIONES_LISTADO = [
        'paciente:id,tipo_documento_id,numero_documento,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido,fecha_nacimiento,sexo',
        'paciente.tipoDocumento:id,codigo',
        'version:id,plantilla_id,version',
        'version.plantilla:id,codigo,nombre',
        'especialista:id,nombres,apellidos',
        'orden:id,cups_id,estado,aval_hasta',
        'orden.cups:id,codigo,nombre',
    ];

    public function __construct(
        private readonly EvaluadorPlantilla $evaluador,
        private readonly CalculadoraPreanestesia $preanestesia,
        private readonly OrdenServicio $ordenes
    ) {}

    public function plantillas(): Collection
    {
        return PlantillaHc::with(['especialidad:id,nombre', 'versionVigente'])->withCount('versiones')->orderBy('nombre')->get();
    }

    public function listar(array $filtros = [], int $porPagina = 20): LengthAwarePaginator
    {
        $query = HistoriaClinica::with(self::RELACIONES_LISTADO);

        foreach (['estado', 'origen'] as $campo) {
            if (! empty($filtros[$campo])) {
                $query->where($campo, strtoupper($filtros[$campo]));
            }
        }
        foreach (['paciente_id', 'especialista_id', 'orden_id'] as $campo) {
            if (! empty($filtros[$campo])) {
                $query->where($campo, (int) $filtros[$campo]);
            }
        }
        if (! empty($filtros['plantilla'])) {
            $query->whereHas('version.plantilla', fn ($q) => $q->where('codigo', $filtros['plantilla']));
        }
        if (! empty($filtros['buscar'])) {
            $termino = trim($filtros['buscar']);
            $like = '%'.mb_strtolower($termino).'%';
            $query->whereHas('paciente', fn ($p) => $p->where('numero_documento', 'like', "{$termino}%")
                ->orWhereRaw('LOWER(primer_nombre) LIKE ?', [$like])->orWhereRaw('LOWER(primer_apellido) LIKE ?', [$like]));
        }

        return $query->orderByRaw("CASE WHEN estado = 'BORRADOR' THEN 0 ELSE 1 END")->orderByDesc('updated_at')->paginate(max(1, min($porPagina, 100)));
    }

    /**
     * @throws ModelNotFoundException
     */
    public function obtenerPorId(int $id): HistoriaClinica
    {
        // En el detalle, la versión se carga completa (incluye el esquema de la plantilla).
        $relaciones = array_values(array_diff(self::RELACIONES_LISTADO, ['version:id,plantilla_id,version']));
        $historia = HistoriaClinica::with([
            ...$relaciones,
            'paciente.municipio:id,codigo,nombre',
            'version',
            'version.plantilla.especialidad:id,nombre',
            'orden.especialidad:id,nombre',
            'orden.contrato:id,numero,entidad_id',
            'orden.contrato.entidad:id,razon_social,sigla',
            'cita:id,fecha,hora_inicio,hora_fin,sede_id,estado',
            'cita.sede:id,nombre',
            'finalizadaPor:id,name',
            'eventos' => fn ($q) => $q->with('usuario:id,name')->latest('id')->limit(50),
        ])->find($id);

        if (! $historia) {
            throw new ModelNotFoundException("No se encontró la historia clínica con ID: {$id}");
        }

        $historia->setAttribute('contexto', $this->contexto($historia->paciente));

        return $historia;
    }

    /**
     * Abre una historia para una cita (o para un paciente con una plantilla). Si ya hay un borrador, lo reutiliza.
     *
     * @throws ValidationException
     */
    public function crear(array $datos, ?int $usuarioId, ?string $ip): HistoriaClinica
    {
        $cita = ! empty($datos['cita_id']) ? Cita::findOrFail($datos['cita_id']) : null;
        $plantilla = PlantillaHc::with('versionVigente')->where('codigo', $datos['plantilla'] ?? 'preanestesia')->where('activo', true)->first();
        if (! $plantilla?->versionVigente) {
            throw ValidationException::withMessages(['plantilla' => ['La plantilla no existe o no tiene una versión publicada.']]);
        }

        if ($cita) {
            if ($cita->estado === 'CANCELADA') {
                throw ValidationException::withMessages(['cita_id' => ['La cita está cancelada.']]);
            }
            $existente = HistoriaClinica::where('cita_id', $cita->id)->whereIn('estado', ['BORRADOR', 'FINALIZADA'])->first();
            if ($existente) {
                return $this->obtenerPorId($existente->id);
            }
        }

        $pacienteId = $cita?->paciente_id ?? ($datos['paciente_id'] ?? null);
        if (! $pacienteId || ! Paciente::whereKey($pacienteId)->exists()) {
            throw ValidationException::withMessages(['paciente_id' => ['Indica el paciente o la cita.']]);
        }

        $iniciales = [];
        foreach ($this->evaluador->campos($plantilla->versionVigente->esquema) as $campo) {
            if (array_key_exists('valor_inicial', $campo)) {
                $iniciales[$campo['id']] = $campo['valor_inicial'];
            }
        }

        $historia = HistoriaClinica::create([
            'paciente_id' => $pacienteId,
            'plantilla_version_id' => $plantilla->versionVigente->id,
            'especialista_id' => $cita?->especialista_id ?? ($datos['especialista_id'] ?? null),
            'cita_id' => $cita?->id,
            'orden_id' => $cita?->orden_id ?? ($datos['orden_id'] ?? null),
            'origen' => 'KIZUNA',
            'estado' => 'BORRADOR',
            'respuestas' => $iniciales,
            'creada_por' => $usuarioId,
        ]);
        $this->evento($historia->id, $usuarioId, 'CREADA', null, $ip);

        return $this->obtenerPorId($historia->id);
    }

    /**
     * Guarda el borrador y devuelve las escalas y alertas recalculadas.
     *
     * @throws ValidationException
     */
    public function guardar(int $id, array $respuestas, ?int $usuarioId, ?string $ip): HistoriaClinica
    {
        $historia = $this->editable($id);
        $esquema = $historia->version->esquema;
        $contexto = $this->contexto($historia->paciente);
        $respuestas = $this->evaluador->conValoresIniciales($esquema, $respuestas, $contexto);

        $errores = $this->evaluador->validar($esquema, $respuestas, $contexto, false);
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        $limpias = $this->evaluador->limpiar($esquema, $respuestas, $contexto);
        $historia->update(['respuestas' => $limpias, 'resultado' => $this->calcular($historia, $limpias, $contexto)]);

        $ultimo = $historia->eventos()->latest('id')->first();
        if (! $ultimo || $ultimo->accion !== 'EDITADA' || $ultimo->usuario_id !== $usuarioId || $ultimo->created_at->lt(now()->subMinutes(10))) {
            $this->evento($historia->id, $usuarioId, 'EDITADA', null, $ip);
        }

        return $this->obtenerPorId($id);
    }

    /**
     * Finaliza la historia: queda inmodificable y aplica sus acciones (en pre-anestesia, el concepto sobre la orden).
     *
     * @throws ValidationException si faltan campos obligatorios.
     */
    public function finalizar(int $id, ?array $respuestas, ?int $usuarioId, ?string $ip): HistoriaClinica
    {
        $historia = $this->editable($id);
        $esquema = $historia->version->esquema;
        $contexto = $this->contexto($historia->paciente);
        $respuestas = $this->evaluador->conValoresIniciales($esquema, $respuestas ?? $historia->respuestas ?? [], $contexto);

        $errores = $this->evaluador->validar($esquema, $respuestas, $contexto, true);
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        $limpias = $this->evaluador->limpiar($esquema, $respuestas, $contexto);
        $resultado = $this->calcular($historia, $limpias, $contexto);

        DB::transaction(function () use ($historia, $limpias, $resultado, $usuarioId, $ip) {
            // Si la cuenta está vinculada a un especialista, él firma la historia.
            $firmante = $usuarioId ? Especialista::where('user_id', $usuarioId)->value('id') : null;
            $historia->update([
                'respuestas' => $limpias,
                'resultado' => $resultado,
                'estado' => 'FINALIZADA',
                'finalizada_en' => now(),
                'finalizada_por' => $usuarioId,
                'especialista_id' => $firmante ?? $historia->especialista_id,
            ]);
            $this->evento($historia->id, $usuarioId, 'FINALIZADA', $resultado['concepto'] ?? null, $ip);
            $this->aplicarAcciones($historia->refresh());
        });

        return $this->obtenerPorId($id);
    }

    /**
     * Anula una historia finalizada (no se borra). Si había dado aval a una orden, la orden vuelve a requerir valoración.
     *
     * @throws ValidationException
     */
    public function anular(int $id, string $motivo, ?int $usuarioId, ?string $ip): HistoriaClinica
    {
        $historia = $this->buscar($id);
        if ($historia->estado === 'ANULADA') {
            throw ValidationException::withMessages(['estado' => ['La historia ya está anulada.']]);
        }

        DB::transaction(function () use ($historia, $motivo, $usuarioId, $ip) {
            $historia->update(['estado' => 'ANULADA', 'motivo_anulacion' => $motivo, 'anulada_en' => now()]);
            $this->evento($historia->id, $usuarioId, 'ANULADA', $motivo, $ip);

            $orden = $historia->orden_id ? OrdenQuirurgica::find($historia->orden_id) : null;
            if ($orden && $orden->historia_id === $historia->id) {
                $orden->update([
                    'estado' => 'APLAZADA', 'motivo_estado' => 'La historia de pre-anestesia fue anulada: requiere nueva valoración.',
                    'concepto' => null, 'asa' => null, 'aval_desde' => null, 'aval_hasta' => null, 'programable_desde' => null, 'historia_id' => null,
                ]);
            }
        });

        return $this->obtenerPorId($id);
    }

    /**
     * Concepto pre-anestésico enviado por un sistema externo (la valoración se hizo fuera de Kizuna).
     *
     * @throws ValidationException
     */
    public function recibirExterna(array $datos, string $sistema): HistoriaClinica
    {
        $orden = ! empty($datos['orden_id'])
            ? OrdenQuirurgica::find($datos['orden_id'])
            : OrdenQuirurgica::where('sistema_origen', $sistema)->where('referencia_externa', $datos['orden_referencia'] ?? null)->first();
        if (! $orden) {
            throw ValidationException::withMessages(['orden_referencia' => ['No se encontró la orden. Envía orden_referencia (la referencia con que registraste la orden) u orden_id.']]);
        }
        if (in_array($orden->estado, ['RECHAZADA', 'CANCELADA'], true)) {
            throw ValidationException::withMessages(['orden_referencia' => ["La orden está {$orden->estado}: no admite concepto."]]);
        }

        if (! empty($datos['referencia'])) {
            $repetida = HistoriaClinica::where('sistema_origen', $sistema)->where('referencia_externa', $datos['referencia'])->first();
            if ($repetida) {
                return $this->obtenerPorId($repetida->id);
            }
        }

        $plantilla = PlantillaHc::with('versionVigente')->where('codigo', 'preanestesia')->firstOrFail();
        $paciente = Paciente::findOrFail($orden->paciente_id);
        $contexto = $this->contexto($paciente);
        $respuestas = array_merge($datos['respuestas'] ?? [], array_filter([
            'asa' => isset($datos['asa']) ? (string) $datos['asa'] : null,
            'concepto' => $datos['concepto'],
            'recomendaciones' => $datos['recomendaciones'] ?? null,
            'motivo' => $datos['motivo'] ?? null,
            'dias_suspension' => $datos['dias_suspension'] ?? null,
        ], fn ($v) => $v !== null));
        $fecha = Carbon::parse($datos['fecha_valoracion']);

        $historia = DB::transaction(function () use ($orden, $plantilla, $respuestas, $contexto, $datos, $sistema, $fecha) {
            $resultado = $this->preanestesia->calcular($respuestas, $contexto);
            $historia = HistoriaClinica::create([
                'paciente_id' => $orden->paciente_id,
                'plantilla_version_id' => $plantilla->versionVigente->id,
                'orden_id' => $orden->id,
                'cita_id' => $orden->citaActual?->id,
                'origen' => 'EXTERNO',
                'sistema_origen' => $sistema,
                'referencia_externa' => $datos['referencia'] ?? null,
                'profesional_externo' => trim(($datos['anestesiologo']['nombre'] ?? '').' · '.($datos['anestesiologo']['registro'] ?? ''), ' ·'),
                'estado' => 'FINALIZADA',
                'respuestas' => $respuestas,
                'resultado' => $resultado,
                'finalizada_en' => $fecha,
            ]);
            $this->evento($historia->id, null, 'RECIBIDA', "Enviada por {$sistema}", null);
            $this->ordenes->aplicarConcepto(
                $orden, $historia->id, $datos['concepto'], isset($datos['asa']) ? (int) $datos['asa'] : null, $fecha,
                (int) ($datos['dias_suspension'] ?? $resultado['medicamentos']['dias_suspension'] ?? 0),
                $datos['aval_hasta'] ?? null, $datos['motivo'] ?? null
            );

            return $historia;
        });

        return $this->obtenerPorId($historia->id);
    }

    public function eventoLectura(int $id, ?int $usuarioId, ?string $ip): void
    {
        $this->evento($id, $usuarioId, 'CONSULTADA', null, $ip);
    }

    /**
     * Escalas, alertas y concepto según la plantilla.
     */
    private function calcular(HistoriaClinica $historia, array $respuestas, array $contexto): array
    {
        return $historia->version->plantilla->codigo === 'preanestesia'
            ? $this->preanestesia->calcular($respuestas, $contexto)
            : ['alertas' => []];
    }

    /**
     * Acciones al finalizar. Pre-anestesia: el concepto define si la orden queda apta y hasta cuándo.
     */
    private function aplicarAcciones(HistoriaClinica $historia): void
    {
        if ($historia->version->plantilla->codigo !== 'preanestesia' || ! $historia->orden_id) {
            return;
        }

        $r = $historia->respuestas;
        $orden = OrdenQuirurgica::find($historia->orden_id);
        if (! $orden || in_array($orden->estado, ['RECHAZADA', 'CANCELADA'], true)) {
            return;
        }

        $this->ordenes->aplicarConcepto(
            $orden,
            $historia->id,
            $r['concepto'],
            isset($r['asa']) ? (int) $r['asa'] : null,
            $historia->finalizada_en,
            (int) ($r['dias_suspension'] ?? $historia->resultado['medicamentos']['dias_suspension'] ?? 0),
            null,
            $r['motivo'] ?? null
        );
        if ($historia->cita_id) {
            Cita::whereKey($historia->cita_id)->update(['estado' => 'ATENDIDA']);
        }
    }

    /**
     * Datos del paciente que las plantillas usan en condiciones y escalas.
     *
     * @return array{paciente: array{sexo: ?string, edad: ?int}}
     */
    private function contexto(?Paciente $paciente): array
    {
        return ['paciente' => ['sexo' => $paciente?->sexo, 'edad' => $paciente?->fecha_nacimiento?->age]];
    }

    private function editable(int $id): HistoriaClinica
    {
        $historia = $this->buscar($id);
        if ($historia->estado !== 'BORRADOR') {
            throw ValidationException::withMessages(['estado' => ['La historia ya está finalizada y no se puede modificar. Si hay un error, anúlala con una justificación.']]);
        }

        return $historia->load(['version.plantilla', 'paciente']);
    }

    private function buscar(int $id): HistoriaClinica
    {
        $historia = HistoriaClinica::find($id);
        if (! $historia) {
            throw new ModelNotFoundException("No se encontró la historia clínica con ID: {$id}");
        }

        return $historia;
    }

    private function evento(int $historiaId, ?int $usuarioId, string $accion, ?string $detalle, ?string $ip): void
    {
        HistoriaEvento::create(['historia_id' => $historiaId, 'usuario_id' => $usuarioId, 'accion' => $accion, 'detalle' => $detalle, 'ip' => $ip]);
    }
}
