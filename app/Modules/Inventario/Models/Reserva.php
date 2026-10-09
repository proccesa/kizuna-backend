<?php

namespace App\Modules\Inventario\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Recurso apartado para un procedimiento: una sala, una unidad (equipo o caja) o una cantidad de insumo.
 */
class Reserva extends Model
{
    protected $table = 'inventario_reservas';

    /**
     * @var list<string>
     */
    protected $fillable = ['item_id', 'unidad_id', 'sala_id', 'sede_id', 'cantidad', 'inicio', 'fin', 'libera_en', 'estado', 'referencia_tipo', 'referencia_id'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['inicio' => 'datetime', 'fin' => 'datetime', 'libera_en' => 'datetime', 'cantidad' => 'integer'];

    /**
     * Reservas activas que ocupan el recurso en algún momento entre $inicio y $fin.
     */
    public function scopeQueCruzan(Builder $query, string $inicio, string $fin): Builder
    {
        return $query->where('estado', 'ACTIVA')->where('inicio', '<', $fin)->where('libera_en', '>', $inicio);
    }
}
