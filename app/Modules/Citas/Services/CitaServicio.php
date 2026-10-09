<?php

namespace App\Modules\Citas\Services;

use App\Modules\Citas\Models\Cita;
use App\Modules\HistoriaClinica\Models\HistoriaClinica;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class CitaServicio
{
    /**
     * Citas por día (agenda del consultorio), con la historia clínica asociada si existe.
     */
    public function listar(array $filtros = [], int $porPagina = 50): LengthAwarePaginator
    {
        $query = Cita::with([
            'paciente:id,tipo_documento_id,numero_documento,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido,fecha_nacimiento,sexo,telefono',
            'paciente.tipoDocumento:id,codigo',
            'especialista:id,nombres,apellidos',
            'sede:id,nombre',
            'cups:id,codigo,nombre',
            'orden:id,cups_id,prioridad,estado',
            'orden.cups:id,codigo,nombre',
        ]);

        if (! empty($filtros['fecha'])) {
            $query->whereDate('fecha', $filtros['fecha']);
        }
        if (! empty($filtros['desde'])) {
            $query->whereDate('fecha', '>=', $filtros['desde']);
        }
        if (! empty($filtros['hasta'])) {
            $query->whereDate('fecha', '<=', $filtros['hasta']);
        }
        foreach (['especialista_id', 'sede_id', 'paciente_id', 'orden_id'] as $campo) {
            if (! empty($filtros[$campo])) {
                $query->where($campo, (int) $filtros[$campo]);
            }
        }
        foreach (['estado', 'tipo'] as $campo) {
            if (! empty($filtros[$campo])) {
                $query->where($campo, strtoupper($filtros[$campo]));
            }
        }

        $paginador = $query->orderBy('fecha')->orderBy('hora_inicio')->paginate(max(1, min($porPagina, 200)));

        $historias = HistoriaClinica::whereIn('cita_id', $paginador->getCollection()->pluck('id'))->where('estado', '!=', 'ANULADA')
            ->get(['id', 'cita_id', 'estado'])->keyBy('cita_id');
        $paginador->getCollection()->each(fn (Cita $c) => $c->setAttribute('historia', $historias->get($c->id)?->only(['id', 'estado'])));

        return $paginador;
    }

    /**
     * @throws ValidationException
     */
    public function cambiarEstado(int $id, string $estado, ?string $motivo = null): Cita
    {
        $cita = Cita::find($id);
        if (! $cita) {
            throw new ModelNotFoundException("No se encontró la cita con ID: {$id}");
        }
        if ($cita->estado !== 'PROGRAMADA') {
            throw ValidationException::withMessages(['estado' => ['Solo se modifican citas programadas.']]);
        }

        $cita->update(['estado' => $estado, 'motivo_cancelacion' => $estado === 'CANCELADA' ? $motivo : null]);

        // Si la cita de pre-anestesia se cancela o el paciente no asiste, la orden vuelve a buscar cupo.
        if ($cita->orden_id && $cita->orden?->estado === 'CITA_ASIGNADA') {
            $cita->orden->update([
                'estado' => 'PENDIENTE_CITA',
                'motivo_estado' => $estado === 'NO_ASISTIO' ? 'El paciente no asistió a la cita de pre-anestesia.' : 'La cita de pre-anestesia se canceló.',
            ]);
        }

        return $cita->refresh();
    }
}
