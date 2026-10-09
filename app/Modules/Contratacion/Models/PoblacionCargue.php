<?php

namespace App\Modules\Contratacion\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoblacionCargue extends Model
{
    protected $table = 'poblacion_cargues';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'poblacion_id',
        'usuario_id',
        'archivo',
        'modo',
        'total',
        'nuevos',
        'actualizados',
        'retirados',
        'con_errores',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'poblacion_id' => 'integer',
        'usuario_id' => 'integer',
        'total' => 'integer',
        'nuevos' => 'integer',
        'actualizados' => 'integer',
        'retirados' => 'integer',
        'con_errores' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
