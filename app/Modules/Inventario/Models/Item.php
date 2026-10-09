<?php

namespace App\Modules\Inventario\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Elemento del catálogo de inventario:
 * - EQUIPO: tipo de equipo biomédico (las unidades físicas son `Unidad`).
 * - INSTRUMENTAL: tipo de caja o set de la central de esterilización (las cajas físicas son `Unidad`).
 * - INSUMO: material que se consume, controlado por cantidad (`Existencia`).
 */
class Item extends Model
{
    use SoftDeletes;

    public const TIPOS = ['EQUIPO', 'INSTRUMENTAL', 'INSUMO'];

    protected $table = 'inventario_items';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tipo', 'codigo', 'nombre', 'descripcion', 'unidad_medida', 'clasificacion_riesgo', 'requiere_calibracion',
        'periodicidad_mantenimiento_meses', 'periodicidad_calibracion_meses', 'minutos_esterilizacion', 'stock_minimo', 'activo',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'requiere_calibracion' => 'boolean',
        'periodicidad_mantenimiento_meses' => 'integer',
        'periodicidad_calibracion_meses' => 'integer',
        'minutos_esterilizacion' => 'integer',
        'stock_minimo' => 'integer',
        'activo' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function unidades(): HasMany
    {
        return $this->hasMany(Unidad::class, 'item_id');
    }

    public function existencias(): HasMany
    {
        return $this->hasMany(Existencia::class, 'item_id');
    }

    public function esPorUnidad(): bool
    {
        return $this->tipo !== 'INSUMO';
    }
}
