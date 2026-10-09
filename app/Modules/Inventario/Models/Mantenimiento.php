<?php

namespace App\Modules\Inventario\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mantenimiento extends Model
{
    public const TIPOS = ['PREVENTIVO', 'CORRECTIVO', 'CALIBRACION'];

    protected $table = 'inventario_mantenimientos';

    /**
     * @var list<string>
     */
    protected $fillable = ['unidad_id', 'tipo', 'inicio', 'fin', 'estado', 'responsable', 'observaciones'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['unidad_id' => 'integer', 'inicio' => 'datetime:Y-m-d H:i', 'fin' => 'datetime:Y-m-d H:i'];

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_id');
    }
}
