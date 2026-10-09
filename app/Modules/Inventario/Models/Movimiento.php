<?php

namespace App\Modules\Inventario\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Movimiento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'inventario_movimientos';

    /**
     * @var list<string>
     */
    protected $fillable = ['item_id', 'sede_id', 'existencia_id', 'tipo', 'cantidad', 'saldo', 'motivo', 'origen', 'usuario_id'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['item_id' => 'integer', 'sede_id' => 'integer', 'cantidad' => 'integer', 'saldo' => 'integer', 'created_at' => 'datetime'];

    public function existencia(): BelongsTo
    {
        return $this->belongsTo(Existencia::class, 'existencia_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
