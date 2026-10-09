<?php

namespace App\Modules\Cirugia\Models;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Citas\Models\Cita;
use App\Modules\Contratacion\Models\Contrato;
use App\Modules\Contratacion\Models\Paciente;
use App\Modules\HistoriaClinica\Models\HistoriaClinica;
use App\Modules\Programacion\Models\Cirugia;
use App\Modules\Servicios\Models\Especialidad;
use App\Modules\Talento\Models\Especialista;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Orden de cirugía que llega de un sistema externo, de un CSV o se registra a mano.
 * Inicia el flujo: orden → cita de pre-anestesia → historia → aval → programación.
 */
class OrdenQuirurgica extends Model
{
    use SoftDeletes;

    public const ESTADOS = ['RECHAZADA', 'PENDIENTE_CITA', 'CITA_ASIGNADA', 'APTA', 'NO_APTA', 'APLAZADA', 'PROGRAMADA', 'OPERADA', 'CANCELADA'];

    public const PRIORIDADES = ['ELECTIVA', 'PRIORITARIA'];

    protected $table = 'ordenes_quirurgicas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'paciente_id',
        'contrato_id',
        'cups_id',
        'especialidad_id',
        'cirujano_id',
        'diagnostico_cie10',
        'diagnostico',
        'prioridad',
        'fecha_orden',
        'medico_ordenante',
        'observaciones',
        'origen',
        'sistema_origen',
        'referencia_externa',
        'estado',
        'motivo_estado',
        'concepto',
        'asa',
        'aval_desde',
        'aval_hasta',
        'programable_desde',
        'historia_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'paciente_id' => 'integer',
        'contrato_id' => 'integer',
        'cups_id' => 'integer',
        'especialidad_id' => 'integer',
        'cirujano_id' => 'integer',
        'asa' => 'integer',
        'historia_id' => 'integer',
        'fecha_orden' => 'date:Y-m-d',
        'aval_desde' => 'date:Y-m-d',
        'aval_hasta' => 'date:Y-m-d',
        'programable_desde' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['aval_vencido'];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class, 'contrato_id');
    }

    public function cups(): BelongsTo
    {
        return $this->belongsTo(Cups::class, 'cups_id');
    }

    public function especialidad(): BelongsTo
    {
        return $this->belongsTo(Especialidad::class, 'especialidad_id');
    }

    public function cirujano(): BelongsTo
    {
        return $this->belongsTo(Especialista::class, 'cirujano_id');
    }

    /**
     * Cirugía vigente (propuesta o aprobada).
     */
    public function cirugia(): HasOne
    {
        return $this->hasOne(Cirugia::class, 'orden_id')->whereIn('cirugias.estado', ['PROPUESTA', 'APROBADA', 'REALIZADA'])->latestOfMany();
    }

    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'orden_id');
    }

    /**
     * Cita de pre-anestesia vigente (la última no cancelada).
     */
    public function citaActual(): HasOne
    {
        return $this->hasOne(Cita::class, 'orden_id')->where('estado', '!=', 'CANCELADA')->latestOfMany();
    }

    public function historia(): BelongsTo
    {
        return $this->belongsTo(HistoriaClinica::class, 'historia_id');
    }

    public function getAvalVencidoAttribute(): bool
    {
        return $this->estado === 'APTA' && $this->aval_hasta !== null && $this->aval_hasta->lt(now()->startOfDay());
    }

    /**
     * Aptas con aval vigente: las que el motor de programación puede tomar.
     */
    public function scopeConAvalVigente(Builder $query): Builder
    {
        return $query->where('estado', 'APTA')->whereDate('aval_hasta', '>=', now()->toDateString());
    }
}
