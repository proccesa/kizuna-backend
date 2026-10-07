<?php

namespace App\Modules\Servicios\Models;

use App\Modules\Catalogos\Models\Cups;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Especialidad extends Model
{
    use SoftDeletes;

    protected $table = 'especialidades';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'activo',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'pivot',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Procedimientos CUPS que esta especialidad puede atender.
     */
    public function cups(): BelongsToMany
    {
        return $this->belongsToMany(Cups::class, 'cups_especialidad', 'especialidad_id', 'cups_id')->withTimestamps();
    }

    public function scopeActivas($query)
    {
        return $query->where('activo', true);
    }
}
