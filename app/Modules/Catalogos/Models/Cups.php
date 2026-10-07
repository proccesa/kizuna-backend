<?php

namespace App\Modules\Catalogos\Models;

use App\Modules\Servicios\Models\Especialidad;
use Database\Factories\CupsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Procedimiento del catálogo oficial CUPS (MinSalud, tabla CUPSRips de SISPRO).
 */
class Cups extends Model
{
    use HasFactory;

    protected $table = 'cups';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'codigo',
        'nombre',
        'nombre_normalizado',
        'seccion',
        'habilitado',
        'uso_codigo',
        'es_quirurgico',
        'sexo',
        'ambito',
        'cobertura',
        'actualizado_minsalud',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'nombre_normalizado',
        'pivot',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'habilitado' => 'boolean',
        'es_quirurgico' => 'boolean',
        'actualizado_minsalud' => 'datetime',
    ];

    /**
     * Especialidades que pueden atender este procedimiento.
     */
    public function especialidades(): BelongsToMany
    {
        return $this->belongsToMany(Especialidad::class, 'cups_especialidad', 'cups_id', 'especialidad_id')->withTimestamps();
    }

    protected static function newFactory(): CupsFactory
    {
        return CupsFactory::new();
    }
}
