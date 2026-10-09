<?php

namespace App\Modules\HistoriaClinica\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoriaEvento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'historia_eventos';

    /**
     * @var list<string>
     */
    protected $fillable = ['historia_id', 'usuario_id', 'accion', 'detalle', 'ip'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['historia_id' => 'integer', 'usuario_id' => 'integer', 'created_at' => 'datetime'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
