<?php

namespace App\Modules\Talento\Models;

use App\Modules\Red\Models\Sede;
use App\Modules\Servicios\Models\Especialidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Franja semanal en la que un especialista atiende una especialidad en una sede.
 */
class Agenda extends Model
{
    use SoftDeletes;

    protected $table = 'agendas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'especialista_id',
        'sede_id',
        'especialidad_id',
        'dias',
        'hora_inicio',
        'hora_fin',
        'vigente_desde',
        'vigente_hasta',
        'consultorio',
        'activo',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'especialista_id' => 'integer',
        'sede_id' => 'integer',
        'especialidad_id' => 'integer',
        'dias' => 'array',
        'vigente_desde' => 'date:Y-m-d',
        'vigente_hasta' => 'date:Y-m-d',
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'horas_semana',
    ];

    public function especialista(): BelongsTo
    {
        return $this->belongsTo(Especialista::class, 'especialista_id');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_id');
    }

    public function especialidad(): BelongsTo
    {
        return $this->belongsTo(Especialidad::class, 'especialidad_id');
    }

    /**
     * Franjas activas que siguen vigentes (o empiezan en el futuro).
     */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('activo', true)->where(function ($q) {
            $q->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', now()->toDateString());
        });
    }

    public function getHoraInicioAttribute(?string $valor): ?string
    {
        return $valor ? substr($valor, 0, 5) : null;
    }

    public function getHoraFinAttribute(?string $valor): ?string
    {
        return $valor ? substr($valor, 0, 5) : null;
    }

    /**
     * Horas de atención que aporta la franja en una semana.
     */
    public function getHorasSemanaAttribute(): float
    {
        if (! $this->hora_inicio || ! $this->hora_fin) {
            return 0;
        }

        [$hi, $mi] = array_map('intval', explode(':', $this->hora_inicio));
        [$hf, $mf] = array_map('intval', explode(':', $this->hora_fin));
        $minutos = max(0, ($hf * 60 + $mf) - ($hi * 60 + $mi));

        return round($minutos * count($this->dias ?? []) / 60, 2);
    }
}
