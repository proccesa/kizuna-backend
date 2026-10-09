<?php

namespace App\Modules\Programacion\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una ejecución del motor: propone un programa quirúrgico que se aprueba o se descarta.
 */
class Corrida extends Model
{
    protected $table = 'programacion_corridas';

    /**
     * @var list<string>
     */
    protected $fillable = ['desde', 'hasta', 'sede_ids', 'estado', 'resumen', 'creada_por', 'aprobada_por', 'cerrada_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['desde' => 'date:Y-m-d', 'hasta' => 'date:Y-m-d', 'sede_ids' => 'array', 'resumen' => 'array', 'cerrada_en' => 'datetime'];

    public function cirugias(): HasMany
    {
        return $this->hasMany(Cirugia::class, 'corrida_id');
    }

    public function creadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }

    public function aprobadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobada_por');
    }
}
