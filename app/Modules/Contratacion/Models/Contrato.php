<?php

namespace App\Modules\Contratacion\Models;

use App\Modules\Catalogos\Models\Cups;
use App\Modules\Catalogos\Models\ModalidadContratacion;
use App\Modules\Catalogos\Models\Regimen;
use App\Modules\Red\Models\Sede;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contrato extends Model
{
    use SoftDeletes;

    /** Días antes del fin en que un contrato se considera "por vencer". */
    public const DIAS_POR_VENCER = 30;

    protected $table = 'contratos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'entidad_id',
        'numero',
        'modalidad_contratacion_id',
        'regimen_id',
        'fecha_inicio',
        'fecha_fin',
        'valor',
        'objeto',
        'activo',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'entidad_id' => 'integer',
        'modalidad_contratacion_id' => 'integer',
        'regimen_id' => 'integer',
        'fecha_inicio' => 'date:Y-m-d',
        'fecha_fin' => 'date:Y-m-d',
        'valor' => 'float',
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'estado',
    ];

    public function entidad(): BelongsTo
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }

    public function modalidad(): BelongsTo
    {
        return $this->belongsTo(ModalidadContratacion::class, 'modalidad_contratacion_id');
    }

    public function regimen(): BelongsTo
    {
        return $this->belongsTo(Regimen::class, 'regimen_id');
    }

    public function sedes(): BelongsToMany
    {
        return $this->belongsToMany(Sede::class, 'contrato_sede', 'contrato_id', 'sede_id');
    }

    /**
     * CUPS pactados con su cantidad y tarifa.
     */
    public function cups(): BelongsToMany
    {
        return $this->belongsToMany(Cups::class, 'contrato_cups', 'contrato_id', 'cups_id')
            ->withPivot(['id', 'cantidad', 'tarifa'])
            ->withTimestamps();
    }

    public function poblaciones(): HasMany
    {
        return $this->hasMany(Poblacion::class, 'contrato_id');
    }

    /**
     * SUSPENDIDO (inactivo), POR_INICIAR, VIGENTE, POR_VENCER o VENCIDO, según las fechas.
     */
    public function getEstadoAttribute(): string
    {
        if (! $this->activo) {
            return 'SUSPENDIDO';
        }

        $hoy = now()->startOfDay();
        if ($this->fecha_inicio?->gt($hoy)) {
            return 'POR_INICIAR';
        }
        if ($this->fecha_fin?->lt($hoy)) {
            return 'VENCIDO';
        }

        return $this->fecha_fin?->lte($hoy->copy()->addDays(self::DIAS_POR_VENCER)) ? 'POR_VENCER' : 'VIGENTE';
    }

    /**
     * Filtra por el estado calculado.
     */
    public function scopeEnEstado(Builder $query, string $estado): Builder
    {
        $hoy = now()->toDateString();
        $limite = now()->addDays(self::DIAS_POR_VENCER)->toDateString();

        return match ($estado) {
            'SUSPENDIDO' => $query->where('activo', false),
            'POR_INICIAR' => $query->where('activo', true)->whereDate('fecha_inicio', '>', $hoy),
            'VENCIDO' => $query->where('activo', true)->whereDate('fecha_fin', '<', $hoy),
            'POR_VENCER' => $query->where('activo', true)->whereDate('fecha_inicio', '<=', $hoy)->whereDate('fecha_fin', '>=', $hoy)->whereDate('fecha_fin', '<=', $limite),
            'VIGENTE' => $query->where('activo', true)->whereDate('fecha_inicio', '<=', $hoy)->whereDate('fecha_fin', '>', $limite),
            // En ejecución: vigentes y por vencer.
            'EN_EJECUCION' => $query->where('activo', true)->whereDate('fecha_inicio', '<=', $hoy)->whereDate('fecha_fin', '>=', $hoy),
            default => $query,
        };
    }
}
