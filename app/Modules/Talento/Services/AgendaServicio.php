<?php

namespace App\Modules\Talento\Services;

use App\Modules\Red\Models\Sede;
use App\Modules\Talento\Models\Agenda;
use App\Modules\Talento\Models\Ausencia;
use App\Modules\Talento\Models\Especialista;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class AgendaServicio
{
    private const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    private const RELACIONES = [
        'sede:id,nombre,prestador_id,dias_atencion,hora_apertura,hora_cierre',
        'sede.prestador:id,razon_social,nombre_comercial',
        'especialidad:id,nombre',
    ];

    /**
     * Franjas vigentes de la red (vista semanal por sede o especialidad).
     */
    public function listar(array $filtros = []): Collection
    {
        $query = Agenda::vigentes()
            ->whereHas('especialista', fn ($q) => $q->where('activo', true))
            ->with([...self::RELACIONES, 'especialista:id,nombres,apellidos']);

        foreach (['sede_id', 'especialidad_id', 'especialista_id'] as $campo) {
            if (! empty($filtros[$campo])) {
                $query->where($campo, (int) $filtros[$campo]);
            }
        }

        return $query->orderBy('hora_inicio')->get();
    }

    /**
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function crear(int $especialistaId, array $datos): Agenda
    {
        $especialista = $this->especialista($especialistaId);
        $datos['activo'] = $datos['activo'] ?? true;
        $this->validarReglas($especialista, $datos);

        $agenda = $especialista->agendas()->create($datos);

        return $agenda->load(self::RELACIONES);
    }

    /**
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function actualizar(int $agendaId, array $datos): Agenda
    {
        $agenda = $this->agenda($agendaId);
        $final = array_merge($agenda->only(['sede_id', 'especialidad_id', 'dias', 'hora_inicio', 'hora_fin', 'consultorio', 'activo']), [
            'vigente_desde' => $agenda->vigente_desde?->toDateString(),
            'vigente_hasta' => $agenda->vigente_hasta?->toDateString(),
        ], $datos);

        $this->validarReglas($this->especialista($agenda->especialista_id), $final, $agenda->id);
        $agenda->update($datos);

        return $agenda->fresh(self::RELACIONES);
    }

    public function eliminar(int $agendaId): bool
    {
        return (bool) $this->agenda($agendaId)->delete();
    }

    /**
     * Novedades del especialista (vacaciones, incapacidades…).
     */
    public function crearAusencia(int $especialistaId, array $datos): Ausencia
    {
        $especialista = $this->especialista($especialistaId);

        $cruce = $especialista->ausencias()
            ->whereDate('fecha_inicio', '<=', $datos['fecha_fin'])
            ->whereDate('fecha_fin', '>=', $datos['fecha_inicio'])
            ->first();
        if ($cruce) {
            throw ValidationException::withMessages([
                'fecha_inicio' => ["Se cruza con otra novedad del {$cruce->fecha_inicio->format('d/m/Y')} al {$cruce->fecha_fin->format('d/m/Y')}."],
            ]);
        }

        return $especialista->ausencias()->create($datos);
    }

    public function eliminarAusencia(int $ausenciaId): bool
    {
        $ausencia = Ausencia::find($ausenciaId);
        if (! $ausencia) {
            throw new ModelNotFoundException("No se encontró la novedad con ID: {$ausenciaId}");
        }

        return (bool) $ausencia->delete();
    }

    /**
     * Reglas de negocio de una franja:
     * - el profesional atiende esa especialidad;
     * - la sede está activa y la franja cabe en sus días y horario;
     * - no se cruza con otra franja activa del mismo profesional (en cualquier sede).
     *
     * @throws ValidationException
     */
    private function validarReglas(Especialista $especialista, array $datos, ?int $ignorarId = null): void
    {
        $errores = [];

        if ($datos['hora_fin'] <= $datos['hora_inicio']) {
            $errores['hora_fin'][] = 'La hora de fin debe ser posterior a la de inicio.';
        }

        if (! empty($datos['vigente_hasta']) && $datos['vigente_hasta'] < $datos['vigente_desde']) {
            $errores['vigente_hasta'][] = 'La vigencia no puede terminar antes de empezar.';
        }

        if (! $especialista->especialidades()->whereKey($datos['especialidad_id'])->exists()) {
            $errores['especialidad_id'][] = 'El profesional no tiene registrada esta especialidad.';
        }

        $sede = Sede::find($datos['sede_id']);
        if (! $sede || ! $sede->activo) {
            $errores['sede_id'][] = 'La sede no existe o está inactiva.';
        } else {
            $fueraDeSede = array_diff($datos['dias'], $sede->dias_atencion ?? []);
            if ($fueraDeSede) {
                $errores['dias'][] = "La sede {$sede->nombre} no atiende el ".$this->nombrarDias($fueraDeSede).'.';
            }
            if ($datos['hora_inicio'] < $sede->hora_apertura || $datos['hora_fin'] > $sede->hora_cierre) {
                $errores['hora_inicio'][] = "La sede {$sede->nombre} atiende de {$sede->hora_apertura} a {$sede->hora_cierre}.";
            }
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        if (! ($datos['activo'] ?? true)) {
            return;
        }

        $cruce = $especialista->agendas()
            ->where('activo', true)
            ->when($ignorarId, fn ($q) => $q->whereKeyNot($ignorarId))
            ->with('sede:id,nombre')
            ->get()
            ->first(fn (Agenda $otra) => $this->seCruzan($otra, $datos));

        if ($cruce) {
            $dias = $this->nombrarDias(array_intersect($cruce->dias, $datos['dias']));
            throw ValidationException::withMessages([
                'hora_inicio' => ["Se cruza con la franja de {$cruce->hora_inicio} a {$cruce->hora_fin} en {$cruce->sede?->nombre} ({$dias})."],
            ]);
        }
    }

    private function seCruzan(Agenda $otra, array $datos): bool
    {
        if (! array_intersect($otra->dias, $datos['dias'])) {
            return false;
        }
        if (! ($datos['hora_inicio'] < $otra->hora_fin && $otra->hora_inicio < $datos['hora_fin'])) {
            return false;
        }

        $desde = $datos['vigente_desde'];
        $hasta = $datos['vigente_hasta'] ?? null;
        $otraDesde = $otra->vigente_desde->toDateString();
        $otraHasta = $otra->vigente_hasta?->toDateString();

        return ($hasta === null || $otraDesde <= $hasta) && ($otraHasta === null || $desde <= $otraHasta);
    }

    /**
     * [1, 3] → "lunes y miércoles"
     */
    private function nombrarDias(array $dias): string
    {
        sort($dias);

        return collect($dias)->map(fn ($d) => self::DIAS[$d] ?? $d)->join(', ', ' y ');
    }

    /**
     * @throws ModelNotFoundException
     */
    private function especialista(int $id): Especialista
    {
        $especialista = Especialista::find($id);
        if (! $especialista) {
            throw new ModelNotFoundException("No se encontró el especialista con ID: {$id}");
        }

        return $especialista;
    }

    /**
     * @throws ModelNotFoundException
     */
    private function agenda(int $id): Agenda
    {
        $agenda = Agenda::find($id);
        if (! $agenda) {
            throw new ModelNotFoundException("No se encontró la franja de agenda con ID: {$id}");
        }

        return $agenda;
    }
}
