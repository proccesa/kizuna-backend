<?php

namespace App\Modules\Contratacion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Grupo de pacientes asignado a un contrato. Se actualiza con cargues de archivo.
 */
class Poblacion extends Model
{
    use SoftDeletes;

    protected $table = 'poblaciones';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contrato_id',
        'nombre',
        'descripcion',
        'ultimo_cargue_en',
        'activo',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'contrato_id' => 'integer',
        'ultimo_cargue_en' => 'datetime',
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'cargue_al_dia',
    ];

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class, 'contrato_id');
    }

    public function pacientes(): BelongsToMany
    {
        return $this->belongsToMany(Paciente::class, 'poblacion_paciente', 'poblacion_id', 'paciente_id')
            ->withPivot(['cohortes', 'activo'])
            ->withTimestamps();
    }

    public function cargues(): HasMany
    {
        return $this->hasMany(PoblacionCargue::class, 'poblacion_id');
    }

    /**
     * Las poblaciones se actualizan cada mes: sin cargue en el mes en curso, los pacientes nuevos no se programan.
     */
    public function getCargueAlDiaAttribute(): bool
    {
        return (bool) $this->ultimo_cargue_en?->isSameMonth(now());
    }
}
