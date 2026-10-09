<?php

namespace App\Modules\Inventario\Models;

use App\Modules\Catalogos\Models\Cups;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que necesita un CUPS. Sin sede es la lista base; con sede, un ajuste (cantidad 0 = no se usa en esa sede).
 */
class RequerimientoCups extends Model
{
    protected $table = 'requerimientos_cups';

    /**
     * @var list<string>
     */
    protected $fillable = ['cups_id', 'sede_id', 'item_id', 'cantidad', 'notas'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['cups_id' => 'integer', 'sede_id' => 'integer', 'item_id' => 'integer', 'cantidad' => 'integer'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function cups(): BelongsTo
    {
        return $this->belongsTo(Cups::class, 'cups_id');
    }
}
