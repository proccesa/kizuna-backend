<?php

namespace App\Modules\Red\Models;

use App\Modules\Inventario\Models\Unidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Quirófano o sala de procedimientos de una sede.
 */
class Sala extends Model
{
    use SoftDeletes;

    public const TIPOS = ['QUIROFANO', 'SALA_PROCEDIMIENTOS', 'SALA_PARTOS', 'OTRA'];

    protected $table = 'salas';

    /**
     * @var list<string>
     */
    protected $fillable = ['sede_id', 'codigo', 'nombre', 'tipo', 'observaciones', 'activo'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['sede_id' => 'integer', 'activo' => 'boolean', 'deleted_at' => 'datetime'];

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_id');
    }

    /**
     * Equipos biomédicos instalados de forma fija en la sala.
     */
    public function equipos(): HasMany
    {
        return $this->hasMany(Unidad::class, 'sala_id');
    }
}
