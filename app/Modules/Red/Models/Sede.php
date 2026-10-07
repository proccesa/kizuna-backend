<?php

namespace App\Modules\Red\Models;

use App\Modules\Catalogos\Models\Municipio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sede extends Model
{
    use SoftDeletes;

    /**
     * Tabla asociada al modelo.
     *
     * @var string
     */
    protected $table = 'sedes';

    /**
     * Atributos asignables en masa.
     *
     * @var list<string>
     */
    protected $fillable = [
        'prestador_id',
        'numero_sede',
        'nombre',
        'municipio_id',
        'direccion',
        'telefono',
        'correo',
        'consultorios',
        'dias_atencion',
        'hora_apertura',
        'hora_cierre',
        'es_principal',
        'activo',
    ];

    /**
     * Casts de atributos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'prestador_id' => 'integer',
        'municipio_id' => 'integer',
        'consultorios' => 'integer',
        'dias_atencion' => 'array',
        'es_principal' => 'boolean',
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Prestador al que pertenece la sede.
     */
    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class, 'prestador_id');
    }

    /**
     * Municipio donde está la sede (DIVIPOLA).
     */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class, 'municipio_id');
    }

    /**
     * Normaliza las horas a HH:MM al serializar.
     */
    protected function serializeHora(?string $hora): ?string
    {
        return $hora ? substr($hora, 0, 5) : null;
    }

    public function getHoraAperturaAttribute(?string $valor): ?string
    {
        return $this->serializeHora($valor);
    }

    public function getHoraCierreAttribute(?string $valor): ?string
    {
        return $this->serializeHora($valor);
    }
}
