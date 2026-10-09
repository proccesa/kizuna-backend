<?php

namespace App\Modules\Inventario\Models;

use App\Modules\Red\Models\Sede;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cantidad de un insumo en una sede, por lote.
 */
class Existencia extends Model
{
    protected $table = 'inventario_existencias';

    /**
     * @var list<string>
     */
    protected $fillable = ['item_id', 'sede_id', 'lote', 'vence', 'cantidad'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['item_id' => 'integer', 'sede_id' => 'integer', 'cantidad' => 'integer', 'vence' => 'date:Y-m-d'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_id');
    }
}
