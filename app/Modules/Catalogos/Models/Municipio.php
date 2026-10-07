<?php

namespace App\Modules\Catalogos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Municipio extends Model
{
    /**
     * Tabla asociada al modelo.
     *
     * @var string
     */
    protected $table = 'municipios';

    /**
     * Atributos asignables en masa.
     *
     * @var list<string>
     */
    protected $fillable = [
        'departamento_id',
        'codigo',
        'nombre',
        'nombre_normalizado',
        'tipo',
        'latitud',
        'longitud',
        'activo',
    ];

    /**
     * Atributos ocultos para la serialización.
     *
     * @var list<string>
     */
    protected $hidden = [
        'nombre_normalizado',
    ];

    /**
     * Casts de atributos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'departamento_id' => 'integer',
        'latitud' => 'float',
        'longitud' => 'float',
        'activo' => 'boolean',
    ];

    /**
     * Relación con el departamento.
     */
    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_id');
    }

    /**
     * Scope para filtrar municipios activos.
     */
    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }
}
